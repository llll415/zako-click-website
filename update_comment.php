<?php
// 文件名: update_comment.php
// Filename: update_comment.php

// 用于安全地处理用户留言的更新请求
// This script is used to securely handle requests for updating user comments.

// 开启或恢复一个会话，这是访问 $_SESSION 变量的前提
// Start or resume a session, which is necessary to access the $_SESSION variable.
session_start();

// 设置HTTP响应头，告诉客户端返回的内容是JSON格式
// Set the HTTP response header to inform the client that the content being returned is in JSON format.
header('Content-Type: application/json');

// 引入数据库配置文件，该文件包含了建立数据库连接所需的信息
// Include the database configuration file, which contains the information needed to establish a database connection.
require_once 'db_config.php';

// 检查数据库连接是否正常
// Check if the database connection is established correctly.
if ($conn->connect_error) {
    // 如果连接失败，编码并输出一个包含错误信息的JSON对象，然后终止脚本
    // If the connection fails, encode and output a JSON object with an error message, then terminate the script.
    echo json_encode(['success' => false, 'message' => '数据库连接失败']);
    exit;
}

// 安全性检查：确保请求必须是POST方法
// Security check: ensure the request method must be POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // 如果不是POST请求，则返回错误信息
    // If it's not a POST request, return an error message.
    echo json_encode(['success' => false, 'message' => '无效的请求方式']);
    exit;
}

// 身份验证：检查用户是否已经通过点击认证（即Session中是否存在'clicked'标志）
// Authentication: check if the user has been certified by clicking (i.e., if the 'clicked' flag exists in the session).
if (!isset($_SESSION['clicked'])) {
    // 如果用户未认证，则无权更新留言
    // If the user is not certified, they are not authorized to update a comment.
    echo json_encode(['success' => false, 'message' => '用户未认证，无法留言']);
    exit;
}

// 获取并清理留言内容
// Get and sanitize the comment content.
// 使用 trim() 移除字符串首尾的空白字符。如果$_POST['comment']未设置，则默认为空字符串。这允许用户清空自己的留言。
// Use trim() to remove whitespace from the beginning and end of the string. Default to an empty string if $_POST['comment'] is not set. This allows users to clear their comments.
$comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';

// 验证留言长度，防止滥用。使用 mb_strlen 是为了正确计算多字节字符（如中文、表情符号）的长度。
// Validate the comment length to prevent abuse. Using mb_strlen is important for correctly counting multibyte characters (like Chinese, emojis).
if (mb_strlen($comment, 'UTF-8') > 100) {
    echo json_encode(['success' => false, 'message' => '留言内容不能超过100个字符']);
    exit;
}

// 获取当前用户的会话ID，作为数据库中记录的唯一标识
// Get the current user's session ID, which serves as the unique identifier for the record in the database.
$sessionId = session_id();

// 使用预处理语句（Prepared Statements）来更新留言，这是防止SQL注入的最佳实践
// Use a prepared statement to update the comment, which is the best practice to prevent SQL injection.
try {
    // 1. 准备SQL更新语句，使用 '?'作为参数占位符
    // 1. Prepare the SQL UPDATE statement, using '?' as placeholders for parameters.
    $stmt = $conn->prepare("UPDATE user_clicks SET comment = ? WHERE session_id = ?");

    // 2. 绑定参数到占位符。'ss' 表示两个参数都是字符串类型。
    // 2. Bind the parameters to the placeholders. 'ss' indicates that both parameters are of string type.
    $stmt->bind_param("ss", $comment, $sessionId);
    
    // 3. 执行已绑定的语句
    // 3. Execute the bound statement.
    if ($stmt->execute()) {
        // 如果执行成功，返回成功的JSON响应
        // If execution is successful, return a success JSON response.
        echo json_encode(['success' => true, 'message' => '留言更新成功']);
    } else {
        // 如果执行失败，返回失败的JSON响应
        // If execution fails, return a failure JSON response.
        echo json_encode(['success' => false, 'message' => '执行数据库更新失败']);
    }
    // 关闭预处理语句，释放资源
    // Close the prepared statement to free up resources.
    $stmt->close();
} catch (Exception $e) {
    // 捕获任何在数据库操作期间可能发生的异常
    // Catch any exceptions that might occur during the database operation.
    echo json_encode(['success' => false, 'message' => '数据库操作出错: ' . $e->getMessage()]);
}

// 关闭数据库连接
// Close the database connection.
$conn->close();
?>