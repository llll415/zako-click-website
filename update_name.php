<?php
// 文件名: update_name.php
// Filename: update_name.php
// 描述: 该脚本用于安全地处理用户昵称的更新请求。
// Description: This script is used to securely handle requests for updating user nicknames.


// 开启或恢复一个会话，用于验证用户是否已登录/认证
// Start or resume a session, used to verify if the user is logged in/certified.
session_start();

// --- 响应头设置 ---
// --- Response Header Settings ---
// 设定返回的内容类型为JSON，这是现代Web API的标准做法
// Set the response content type to JSON, which is a standard practice for modern web APIs.
header('Content-Type: application/json');

// --- 数据库连接 ---
// --- Database Connection ---
// 引入唯一的数据库配置文件。这样可以集中管理配置，避免冗余和冲突。
// Include the single source of truth for database configuration. This centralizes management and avoids redundancy and conflicts.
require_once 'db_config.php';

// --- 安全性与输入验证 ---
// --- Security and Input Validation ---

// 1. 检查来自 db_config.php 的数据库连接对象是否有效
// 1. Check if the database connection object from db_config.php is valid.
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => '数据库连接失败: ' . $conn->connect_error]);
    exit;
}

// 2. 确保请求是POST方法，必需的'nickname'参数存在，且用户已通过Session认证
// 2. Ensure the request is a POST method, the required 'nickname' parameter exists, and the user is certified via session.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['nickname']) || !isset($_SESSION['clicked'])) {
    // 如果不满足任一条件，返回一个包含错误信息的JSON响应并终止脚本执行
    // If any condition is not met, return a JSON response with an error message and terminate the script.
    echo json_encode(['success' => false, 'message' => '无效的请求']);
    exit;
}

// 3. 清理和验证昵称数据
// 3. Sanitize and validate the nickname data.
$nickname = trim($_POST['nickname']); // 使用 trim() 去除用户输入内容两端的空白字符
                                      // Use trim() to remove whitespace from both ends of the user input.

// 检查清理后的昵称是否为空
// Check if the sanitized nickname is empty.
if (empty($nickname)) {
    echo json_encode(['success' => false, 'message' => '昵称不能为空']);
    exit;
}
// 限制昵称长度。使用 mb_strlen 是为了正确处理多字节字符（如中文）。
// Limit the nickname length. Use mb_strlen to correctly handle multibyte characters (like Chinese).
if (mb_strlen($nickname, 'UTF-8') > 10) {
    echo json_encode(['success' => false, 'message' => '昵称太长了（最多10个字符）']);
    exit;
}

// 4. 安全处理：对昵称进行HTML转义，以防止跨站脚本攻击(XSS)。
// 4. Security measure: Escape the nickname for HTML to prevent Cross-Site Scripting (XSS) attacks.
$nickname = htmlspecialchars($nickname, ENT_QUOTES, 'UTF-8');
$sessionId = session_id(); // 获取当前用户的会话ID / Get the current user's Session ID.

// --- 数据库操作 ---
// --- Database Operations ---

// 健壮性检查：在更新前，确保'nickname'列存在。这是一个很好的防御性编程习惯。
// Robustness check: Ensure the 'nickname' column exists before updating. This is a good defensive programming practice.
$checkColumnQuery = "SHOW COLUMNS FROM `user_clicks` LIKE 'nickname'";
$result = $conn->query($checkColumnQuery);
if ($result && $result->num_rows == 0) {
    // 如果'nickname'列不存在，尝试动态添加它
    // If the 'nickname' column does not exist, try to add it dynamically.
    if (!$conn->query("ALTER TABLE `user_clicks` ADD `nickname` VARCHAR(50) NOT NULL DEFAULT '匿名Zako' AFTER `session_id`")) {
         echo json_encode(['success' => false, 'message' => '添加昵称列失败，请联系管理员']);
         $conn->close();
         exit;
    }
}

// 使用 try-catch 块来捕获数据库操作中可能出现的异常
// Use a try-catch block to handle potential exceptions during database operations.
try {
    // 使用预处理语句来更新数据，防止SQL注入
    // Use a prepared statement to update data to prevent SQL injection.
    $stmt = $conn->prepare("UPDATE user_clicks SET nickname = ? WHERE session_id = ?");
    
    // "ss" 表示绑定的两个参数都是字符串(string)类型
    // "ss" indicates that both parameters being bound are of the string type.
    $stmt->bind_param("ss", $nickname, $sessionId);
    
    // 执行更新操作
    // Execute the update operation.
    if ($stmt->execute()) {
        // 如果成功，返回成功的JSON响应
        // If successful, return a success JSON response.
        echo json_encode(['success' => true, 'message' => '昵称更新成功！']);
    } else {
        // 如果失败，返回失败的JSON响应
        // If it fails, return a failure JSON response.
        echo json_encode(['success' => false, 'message' => '昵称更新失败']);
    }
} catch (Exception $e) {
    // 捕获任何异常并返回详细错误信息
    // Catch any exception and return a detailed error message.
    echo json_encode(['success' => false, 'message' => '数据库操作异常: ' . $e->getMessage()]);
}

// 关闭数据库连接，释放资源
// Close the database connection to free up server resources.
$conn->close();
?>