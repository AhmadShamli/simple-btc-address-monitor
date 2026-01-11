<?php
/**
 * Simple BTC Address Balance Monitor
 * Single file application using SQLite and Bitcoin RPC
 */

// Load Configuration
$config = require __DIR__ . '/config.php';

// Session Start
session_start();

// Database Initialization
try {
    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create Table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS addresses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT NOT NULL,
        address TEXT NOT NULL UNIQUE,
        balance REAL DEFAULT 0,
        last_updated DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// Helpers
function rpc_call($method, $params = [])
{
    global $config;
    $payload = json_encode([
        'jsonrpc' => '1.0',
        'id' => 'curltest',
        'method' => $method,
        'params' => $params,
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://{$config['rpc_host']}:{$config['rpc_port']}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$config['rpc_user']}:{$config['rpc_password']}");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        return ['error' => "RPC Error: HTTP $http_code", 'raw' => $response];
    }

    return json_decode($response, true);
}

function blockchain_info_batch($addresses)
{
    // Logic adapted from user reference
    // Returns array of [address => balance_btc] or null on failure

    $is_array = is_array($addresses);
    $addr_str = $is_array ? implode(',', $addresses) : $addresses;

    $url = "https://blockchain.info/balance?active=" . $addr_str;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        if (is_array($data)) {
            $balances = [];
            // Handle single address response structure check if needed, 
            // but balance?active=A,B returns {A: {...}, B: {...}}
            // balance?active=A returns {A: {...}} as well usually, checking standard behavior.

            foreach ($data as $address => $balance) {
                if (isset($balance['final_balance'])) {
                    $balances[$address] = (float) $balance['final_balance'] / 100000000;
                }
            }
            return $balances;
        }
    }
    return [];
}

function blockcypher_api_call($address)
{
    // Using BlockCypher API as secondary fallback
    $url = "https://api.blockcypher.com/v1/btc/main/addrs/" . urlencode($address) . "/balance";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $data = json_decode($response, true);
        if (isset($data['final_balance'])) {
            return (float) $data['final_balance'] / 100000000; // Convert satoshis to BTC
        }
    }
    return false;
}

function update_balance($address_id, $address)
{
    global $pdo, $config;

    $balance = false;
    $method = "RPC";

    // Try RPC first
    $result = rpc_call('scantxoutset', ['start', ["addr($address)"]]);

    if (isset($result['result']['total_amount'])) {
        $balance = $result['result']['total_amount'];
    } elseif ($config['allow_fallback']) {
        // Fallback to Blockchain.info batch (single address)
        $batch_result = blockchain_info_batch([$address]);
        if (isset($batch_result[$address])) {
            $balance = $batch_result[$address];
            $method = "Blockchain.info";
        } else {
            // Secondary fallback to BlockCypher
            $balance = blockcypher_api_call($address);
            if ($balance !== false) {
                $method = "BlockCypher";
            }
        }
    }

    if ($balance !== false) {
        $stmt = $pdo->prepare("UPDATE addresses SET balance = ?, last_updated = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$balance, $address_id]);
        return ['balance' => $balance, 'method' => $method];
    }

    return false;
}

function process_batch_update($rows)
{
    global $pdo, $config;

    $addresses = array_column($rows, 'address');
    $addr_map = array_column($rows, 'id', 'address'); // Addr -> ID
    $results = []; // Addr -> ['balance' => x, 'method' => y]

    // 1. Try RPC Batch
    $descriptors = array_map(function ($a) {
        return "addr($a)"; }, $addresses);
    $rpc_res = rpc_call('scantxoutset', ['start', $descriptors]);

    if (isset($rpc_res['result']['unspents'])) {
        // Initialize all as 0 for RPC success
        $balances = array_fill_keys($addresses, 0.0);

        foreach ($rpc_res['result']['unspents'] as $utxo) {
            // desc format: "addr(ADDRESS)#checksum"
            // Simple extraction
            foreach ($addresses as $addr) {
                if (strpos($utxo['desc'], $addr) !== false) {
                    $balances[$addr] += $utxo['amount'];
                }
            }
        }

        foreach ($balances as $addr => $bal) {
            $results[$addr] = ['balance' => $bal, 'method' => 'RPC Batch'];
        }
    } elseif ($config['allow_fallback']) {
        // 2. Fallback: Blockchain.info Batch
        // Split into chunks of 50 just in case logic is reused elsewhere
        $chunks = array_chunk($addresses, 50);
        foreach ($chunks as $chunk) {
            $api_bal = blockchain_info_batch($chunk);
            if (!empty($api_bal)) {
                foreach ($api_bal as $addr => $bal) {
                    $results[$addr] = ['balance' => $bal, 'method' => 'Blockchain.info Batch'];
                }
            }
        }
    }

    // 3. Process Results & Fallback for missing
    foreach ($rows as $row) {
        $addr = $row['address'];
        $id = $row['id'];

        $balance = false;
        $method = '';

        if (isset($results[$addr])) {
            $balance = $results[$addr]['balance'];
            $method = $results[$addr]['method'];
        } elseif ($config['allow_fallback']) {
            // Tertiary: BlockCypher (Single)
            $bal = blockcypher_api_call($addr);
            if ($bal !== false) {
                $balance = $bal;
                $method = 'BlockCypher';
            }
        }

        if ($balance !== false) {
            $stmt = $pdo->prepare("UPDATE addresses SET balance = ?, last_updated = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$balance, $id]);
            echo "Updated $addr: $balance BTC ($method)\n";
        } else {
            echo "Failed to update $addr\n";
        }
    }
}

// CLI / Cronjob Mode
if (php_sapi_name() === 'cli' || (isset($_GET['action']) && $_GET['action'] === 'cron')) {
    // Hard limit of 50 to match API best practices, regardless of config
    $limit = min((int) $config['cron_batch_limit'], 50);

    $stmt = $pdo->prepare("SELECT id, address FROM addresses ORDER BY last_updated ASC LIMIT ?");
    $stmt->execute([$limit]);
    $addrs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($addrs)) {
        if (php_sapi_name() === 'cli')
            echo "No addresses in database.\n";
    } else {
        echo "Starting batch update for " . count($addrs) . " addresses...\n";
        process_batch_update($addrs);
    }

    if (php_sapi_name() === 'cli')
        exit;
}

// Web Logic
// Authentication
if (isset($_POST['login'])) {
    if ($_POST['password'] === $config['admin_password']) {
        $_SESSION['authenticated'] = true;
    } else {
        $error = "Invalid password";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION['authenticated'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>Login - BTC Monitor</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                background: #f4f7f6;
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
                margin: 0;
            }

            .login-card {
                background: #fff;
                padding: 2rem;
                border-radius: 8px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                width: 100%;
                max-width: 400px;
            }

            h2 {
                margin-top: 0;
                color: #333;
            }

            input[type="password"] {
                width: 100%;
                padding: 10px;
                margin: 10px 0;
                border: 1px solid #ddd;
                border-radius: 4px;
                box-sizing: border-box;
            }

            button {
                width: 100%;
                padding: 10px;
                background: #f7931a;
                border: none;
                color: white;
                border-radius: 4px;
                cursor: pointer;
                font-weight: bold;
            }

            button:hover {
                background: #e88a18;
            }

            .error {
                color: #d9534f;
                margin-bottom: 10px;
                font-size: 0.9rem;
            }
        </style>
    </head>

    <body>
        <div class="login-card">
            <h2>BTC Address Monitor</h2>
            <?php if (isset($error))
                echo "<div class='error'>$error</div>"; ?>
            <form method="POST">
                <input type="password" name="password" placeholder="Admin Password" required autofocus>
                <button type="submit" name="login">Login</button>
            </form>
        </div>
    </body>

    </html>
    <?php
    exit;
}

// Actions
$message = "";
if (isset($_POST['add_address'])) {
    $input = $_POST['address_input'];
    $lines = preg_split('/[\r\n]+/', trim($input));

    $added = 0;
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO addresses (label, address) VALUES (?, ?)");

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line))
            continue;

        // Parse line: "label,address" or just "address"
        $parts = explode(',', $line);
        if (count($parts) >= 2) {
            // Assume last part is address, rest is label (allows commas in label somewhat)
            $addr = trim(array_pop($parts));
            $label = trim(implode(',', $parts));
        } else {
            $addr = trim($parts[0]);
            $label = "Unlabeled";
        }

        if (empty($addr))
            continue;

        if ($stmt->execute([$label, $addr])) {
            if ($stmt->rowCount() > 0)
                $added++;
        }
    }
    $message = "Added $added new addresses.";
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM addresses WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    $message = "Address deleted.";
}

if (isset($_GET['update'])) {
    $stmt = $pdo->prepare("SELECT address FROM addresses WHERE id = ?");
    $stmt->execute([$_GET['update']]);
    $addr = $stmt->fetchColumn();
    if ($addr) {
        $res = update_balance($_GET['update'], $addr);
        $message = ($res !== false) ? "Updated via {$res['method']}! Balance: {$res['balance']} BTC" : "Failed to update balance.";
    }
}

// Fetch Stats & Addresses
$blockchain_info = rpc_call('getblockchaininfo');
$stats = $pdo->query("SELECT COUNT(*) as count, SUM(balance) as total FROM addresses")->fetch(PDO::FETCH_ASSOC);
$addresses = $pdo->query("SELECT * FROM addresses ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Dashboard - BTC Monitor</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #333;
            margin: 0;
        }

        .container {
            max-width: 1000px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        h1 {
            margin: 0;
            color: #f7931a;
        }

        .logout {
            color: #666;
            text-decoration: none;
        }

        .logout:hover {
            text-decoration: underline;
        }

        .card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
        }

        textarea {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            min-height: 80px;
            font-family: monospace;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            transition: background 0.2s;
        }

        .btn-primary {
            background: #f7931a;
            color: white;
        }

        .btn-primary:hover {
            background: #e88a18;
        }

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.8rem;
        }

        .btn-update {
            background: #28a745;
            color: white;
            text-decoration: none;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
            text-decoration: none;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #fcfcfc;
            color: #666;
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .balance {
            font-family: 'Courier New', Courier, monospace;
            font-weight: bold;
            color: #2c3e50;
        }

        .timestamp {
            font-size: 0.8rem;
            color: #999;
        }

        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }

        .alert-info {
            background: #e7f3ff;
            color: #004085;
            border: 1px solid #b8daff;
        }

        /* Stats Dashboard */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border-top: 4px solid #f7931a;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 1.4rem;
            font-weight: bold;
            color: #333;
        }

        .stat-value.btc {
            color: #f7931a;
        }
    </style>
</head>

<body>
    <div class="container">
        <header>
            <h1>BTC Monitor</h1>
            <a href="?logout=1" class="logout">Logout</a>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Balance</div>
                <div class="stat-value btc"><?php echo number_format($stats['total'] ?: 0, 8); ?> BTC</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Addresses</div>
                <div class="stat-value"><?php echo $stats['count']; ?></div>
            </div>
            <?php if (isset($blockchain_info['result'])):
                $bi = $blockchain_info['result']; ?>
                <div class="stat-card">
                    <div class="stat-label">Block Height</div>
                    <div class="stat-value"><?php echo number_format($bi['blocks']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Network</div>
                    <div class="stat-value" style="text-transform: capitalize;"><?php echo $bi['chain']; ?></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Add Addresses</h3>
            <div style="margin-bottom: 1rem; font-size: 0.9rem; color: #666;">
                Format per line: <code>Address</code> or <code>Label,Address</code>
            </div>
            <form method="POST">
                <div class="form-group">
                    <textarea name="address_input"
                        placeholder="Exchange,1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa&#10;Savings,1JTK7s9YVYywfm5XUH7RNhHJH1LshCaRFR&#10;1PWo3JeB9jrGwfHDNpdGK54CRas7fsBzXU"
                        required style="min-height: 120px;"></textarea>
                </div>
                <button type="submit" name="add_address" class="btn btn-primary">Add Addresses</button>
            </form>
        </div>

        <div class="card">
            <h3>Monitored Addresses</h3>
            <table>
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>Address</th>
                        <th>Balance (BTC)</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($addresses)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #999;">No addresses added yet.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($addresses as $row): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['label']); ?></strong></td>
                            <td><code style="font-size: 0.9rem;"><?php echo htmlspecialchars($row['address']); ?></code>
                            </td>
                            <td class="balance"><?php echo number_format($row['balance'], 8); ?></td>
                            <td class="timestamp"><?php echo $row['last_updated'] ?: 'Never'; ?></td>
                            <td>
                                <a href="?update=<?php echo $row['id']; ?>" class="btn btn-sm btn-update">Update</a>
                                <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-delete"
                                    onclick="return confirm('Delete this address?')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="font-size: 0.8rem; color: #999; text-align: center;">
            Cronjob URL:
            <code><?php echo (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[PHP_SELF]"; ?>?action=cron</code>
        </div>
    </div>
</body>

</html>