# Simple BTC Address Balance Monitor

A lightweight, single-file PHP application to monitor Bitcoin address balances using a local Bitcoin Core node (RPC) with automatic failover to public APIs.

## Features

- **Self-Hosted**: Runs on your own server with PHP and SQLite.
- **Bitcoin RPC Integration**: Uses `scantxoutset` for accurate, fast balance checks without needing a fully indexed node (`txindex=1` not required).
- **Robust Fallback System**:
    1. **Primary**: Local Bitcoin Node (RPC).
    2. **Secondary**: Blockchain.info API (optimized batch queries).
    3. **Tertiary**: BlockCypher API.
- **Batch Management**: 
    - Add thousands of addresses via a single text area.
    - Optimized cronjob updates 50 addresses per batch to respect API limits.
- **Privacy & Speed**: Checks your local node first, keeping your address interest private when possible.
- **Insights**: Displays network status, block height, and total accumulated balance.

## Requirements

- PHP 7.4 or higher
- PHP `curl` and `pdo_sqlite` extensions
- Bitcoin Core Node (optional, but recommended for primary data source)
- SQLite3 (usually built-in with PHP)

## Installation

1. **Clone or Download** this repository.
2. **Configure**: Rename or defaults are set in `config.php`.
   - Open `config.php` and set your:
     - `rpc_host`, `rpc_port`, `rpc_user`, `rpc_password`
     - `admin_password` (Default: `admin123` - **CHANGE THIS**)
3. **Permissions**: Ensure the directory is writable by the web server (for `addr_monitor.db` creation).

## Usage

### Web Interface
1. Navigate to `index.php` in your browser.
2. Login with your admin password.
3. Paste addresses into the input area.
   - Format: `Address` (sets label to 'Unlabeled')
   - Format: `Label,Address` (sets custom label)

### Automatic Background Updates (Cron)
To keep balances up-to-date automatically, set up a cronjob. The script processes 50 addresses per run to ensuring stability.

**Example Crontab (Runs every 10 minutes):**
```bash
*/10 * * * * /usr/bin/php /path/to/simple-btc-address-monitor/index.php >> /dev/null 2>&1
```
Or call it via URL (less secure if exposed):
`http://your-site.com/index.php?action=cron`

## Configuration Options

In `config.php`:
- `cron_batch_limit`: Number of addresses to process per cron run (Hard capped at 50 internally).
- `allow_fallback`: Set to `true` to use public APIs if your local node is offline.
