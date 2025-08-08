# Wordpress_ReverseShell

A **stealth WordPress plugin** designed for penetration testing and authorized security assessments. Masquerades as a legitimate "System Health Monitor" while providing reverse shell capabilities through multiple connection methods.

## ⚠️ **DISCLAIMER**
This tool is intended for **authorized penetration testing and security research ONLY**. Use only on systems you own or have explicit written permission to test. Unauthorized use is illegal and unethical.

## 🎯 **Features**

### **Stealth Design**
- **Legitimate appearance**: Looks like a professional WordPress health monitoring plugin
- **Clean admin interface**: Professional WordPress-style dashboard
- **Normal plugin behavior**: Follows WordPress development standards
- **No suspicious file names**: Uses standard WordPress naming conventions

### **Multiple Connection Methods**
1. **fsockopen** - Standard PHP socket connection
2. **stream_socket_client** - Alternative PHP socket method  
3. **Bash /dev/tcp** - Direct bash redirection method
4. **Try All Methods** - Sequential fallback testing

### **Advanced Capabilities**
- **Full shell session handling** with command execution logging
- **Real-time command logging** with timestamps and execution metrics
- **Session management** with timeout protection
- **Input validation** for hosts/IPs and ports
- **Comprehensive error handling** and diagnostics
- **Log export functionality** for forensic analysis


## 🚀 **Installation Guide**

### **Method 1: Direct File Upload**

1. **Download the plugin file**:
   ```bash
   wget https://github.com/bhanunamikaze/Wordpress_ReverseShell/raw/main/system-health-monitor.php
   ```

2. **Upload to WordPress**:
   - Access your WordPress server via FTP/SSH
   - Navigate to `/wp-content/plugins/`
   - Create directory: `mkdir system-health-monitor`
   - Upload file: `system-health-monitor.php` to the new directory

3. **Activate the plugin**:
   - Log into WordPress admin panel
   - Go to **Plugins** → **Installed Plugins**
   - Find "System Health Monitor"
   - Click **Activate**

### **Method 2: ZIP Upload (Recommended)**

1. **Create plugin package**:
   ```bash
   # Create directory structure
   mkdir system-health-monitor
   cp system-health-monitor.php system-health-monitor/
   zip -r system-health-monitor.zip system-health-monitor/
   ```

2. **Upload via WordPress admin**:
   - Login to WordPress admin panel
   - Navigate to **Plugins** → **Add New**
   - Click **Upload Plugin**
   - Choose `system-health-monitor.zip`
   - Click **Install Now**
   - Click **Activate Plugin**

### **Method 3: Manual Installation**

1. **Server access required**:
   ```bash
   # SSH into WordPress server
   ssh user@your-wordpress-server.com
   
   # Navigate to plugins directory
   cd /var/www/html/wp-content/plugins/
   
   # Create plugin directory
   sudo mkdir system-health-monitor
   sudo chown www-data:www-data system-health-monitor
   
   # Upload and set permissions
   sudo cp /path/to/system-health-monitor.php system-health-monitor/
   sudo chown www-data:www-data system-health-monitor/system-health-monitor.php
   sudo chmod 644 system-health-monitor/system-health-monitor.php
   ```


## 📝 **Legal Notice**

This tool is provided for educational and authorized testing purposes only. Users are responsible for:

- **Obtaining proper authorization** before use
- **Complying with all applicable laws** and regulations
- **Using only on owned or explicitly authorized systems**
- **Responsible disclosure** of any vulnerabilities found

**The authors assume no liability for misuse of this tool.**


## 📄 **License**

This project is licensed under the MIT License - see the LICENSE file for details.

