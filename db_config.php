<?php
// 文件名: db_config.php
// Filename: db_config.php

// 这个文件专门用于存放数据库连接信息，以提高安全性。
// This file is dedicated to storing database connection information to enhance security.

// --- 数据库配置 ---
// --- Database Configuration ---

// 数据库服务器地址 (通常是 'localhost' 或 '127.0.0.1')
// Database server address (usually 'localhost' or '127.0.0.1')
$servername = "localhost";

// 数据库用户名
// Database username
$username = "用户";

// 数据库密码
// Database password
$password = "密码";

// 要连接的数据库名称
// The name of the database to connect to
$dbname = "数据库名";

// --- 数据库连接 ---
// --- Database Connection ---

// 创建一个新的 mysqli 连接对象
// Create a new mysqli connection object
$conn = new mysqli($servername, $username, $password, $dbname);

// 检查连接是否成功
// Check if the connection was successful
if ($conn->connect_error) {
    // 如果连接失败，主文件(index.php)中的逻辑会捕获并处理这个错误。
    // If the connection fails, the logic in the main file (index.php) will catch and handle this error.

    // 我们在这里不直接终止(die)程序，以保持主文件的流程控制。
    // We don't terminate (die) the script here directly to maintain flow control in the main file.
} else {
    // 如果连接成功，设置字符集为 utf8mb4，这是最佳实践，可以更好地支持表情符号(emoji)等。
    // If the connection is successful, set the character set to utf8mb4. This is a best practice for better support of emojis and other characters.
    $conn->set_charset("utf8mb4");
}
?>