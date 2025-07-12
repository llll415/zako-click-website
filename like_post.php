<?php
// like_post.php

// 开启Session，用于未来可能的扩展，例如记录点赞历史到Session中
// Start the session for potential future use, such as recording like history in the session.
session_start();
// 设置响应头为JSON格式，告知浏览器本次请求返回的是JSON数据
// Set the response header to JSON format to inform the browser that this request returns JSON data.
header('Content-Type: application/json');

// --- 引入数据库配置 ---
// --- Include Database Configuration ---
// 使用 require_once 确保数据库配置文件只被引入一次，避免重复定义变量或函数
// Use require_once to ensure the database config file is included only once, avoiding redefinition of variables or functions.
require_once 'db_config.php';

// --- 辅助函数 ---
// --- Helper Functions ---

/**
 * 从POST请求中获取点赞者的客户端UUID
 * Gets the liker's client UUID from the POST request.
 * @return string|null 返回客户端UUID；如果不存在则返回null / Returns the client UUID, or null if not present.
 */
function getLikerUuid() {
    // 使用null合并运算符(??)来安全地获取值，如果'client_uuid'不存在，则返回null
    // Use the null coalescing operator (??) to safely get the value; returns null if 'client_uuid' does not exist.
    return $_POST['client_uuid'] ?? null;
}

// --- 主要逻辑 ---
// --- Main Logic ---

// 检查HTTP请求方法是否为POST，这是API接口的安全惯例
// Check if the HTTP request method is POST, a security best practice for API endpoints.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // 如果不是POST请求，返回错误信息并终止脚本
    // If it's not a POST request, return an error message and terminate the script.
    echo json_encode(['success' => false, 'message' => '无效的请求方法.']);
    exit;
}

// 检查数据库连接是否成功
// Check if the database connection was successful.
if ($conn->connect_error) {
    // 如果连接失败，返回错误信息并终止脚本
    // If the connection failed, return an error message and terminate the script.
    echo json_encode(['success' => false, 'message' => '数据库连接失败.']);
    exit;
}

// 获取并验证从前端发送过来的参数
// Get and validate the parameters sent from the frontend.
$liked_user_id = $_POST['user_id'] ?? null; // 被点赞用户的ID / The ID of the user being liked.
$liker_uuid = getLikerUuid(); // 点赞者的UUID / The UUID of the user who is liking.

// 确保关键参数都存在且格式正确
// Ensure that crucial parameters exist and have the correct format.
if (empty($liked_user_id) || !is_numeric($liked_user_id) || empty($liker_uuid)) {
    echo json_encode(['success' => false, 'message' => '无效或缺失的参数.']);
    exit;
}

// 安全检查：防止用户给自己点赞
// Security check: Prevent a user from liking themselves.
$stmt_check_self = $conn->prepare("SELECT client_uuid FROM user_clicks WHERE id = ?");
$stmt_check_self->bind_param("i", $liked_user_id);
$stmt_check_self->execute();
$result_check_self = $stmt_check_self->get_result();
if ($result_check_self->num_rows > 0) {
    $liked_user_uuid = $result_check_self->fetch_assoc()['client_uuid'];
    // 比较点赞者和被点赞者的UUID
    // Compare the UUID of the liker and the user being liked.
    if ($liker_uuid === $liked_user_uuid) {
        echo json_encode(['success' => false, 'message' => '你不能给自己点赞哦！']);
        exit;
    }
} else {
    // 如果根据ID找不到被点赞的用户
    // If the user being liked cannot be found by their ID.
    echo json_encode(['success' => false, 'message' => '被点赞的用户不存在.']);
    exit;
}
$stmt_check_self->close();

// 使用数据库事务，确保数据操作的原子性和一致性
// Use a database transaction to ensure atomicity and consistency of data operations.
// 这意味着后续的两个数据库操作（插入和更新）要么都成功，要么都失败。
// This means the two subsequent database operations (insert and update) will either both succeed or both fail.
$conn->begin_transaction();

try {
    // 步骤 1: 尝试将点赞记录插入 user_likes 表
    // Step 1: Try to insert the like record into the user_likes table.
    // user_likes表上设置了 (liker_uuid, liked_user_id) 的唯一键约束，可以天然防止重复点赞
    // The user_likes table has a unique key constraint on (liker_uuid, liked_user_id), which naturally prevents duplicate likes.
    $stmt_insert = $conn->prepare("INSERT INTO user_likes (liker_uuid, liked_user_id) VALUES (?, ?)");
    $stmt_insert->bind_param("si", $liker_uuid, $liked_user_id);
    $stmt_insert->execute();

    // 步骤 2: 如果插入成功（即不是重复点赞），则更新 user_clicks 表中的点赞计数
    // Step 2: If the insertion was successful (i.e., not a duplicate like), update the likes count in the user_clicks table.
    // $stmt_insert->affected_rows > 0 表示有一条新记录被成功插入
    // $stmt_insert->affected_rows > 0 indicates that a new record was successfully inserted.
    if ($stmt_insert->affected_rows > 0) {
        $stmt_update = $conn->prepare("UPDATE user_clicks SET likes_count = likes_count + 1 WHERE id = ?");
        $stmt_update->bind_param("i", $liked_user_id);
        $stmt_update->execute();
        // 如果更新操作没有影响任何行（例如，用户ID不存在），则这是一个异常情况，需要回滚
        // If the update operation affected no rows (e.g., the user ID doesn't exist), this is an exceptional case and requires a rollback.
        if ($stmt_update->affected_rows === 0) {
            throw new Exception("更新点赞计数失败.");
        }
        $stmt_update->close();
    }
    $stmt_insert->close();
    
    // 如果所有操作都成功，提交事务，使更改永久生效
    // If all operations were successful, commit the transaction to make the changes permanent.
    $conn->commit();

    // 操作成功后，获取最新的点赞数并返回给前端
    // After a successful operation, get the latest like count and return it to the frontend.
    $result = $conn->query("SELECT likes_count FROM user_clicks WHERE id = " . (int)$liked_user_id);
    $new_count = $result->fetch_assoc()['likes_count'];

    echo json_encode(['success' => true, 'new_count' => $new_count]);

} catch (Exception $e) {
    // 如果在try块中发生任何错误，回滚事务，撤销所有未提交的更改
    // If any error occurs in the try block, roll back the transaction, undoing all uncommitted changes.
    $conn->rollback();
    
    // 检查是否为唯一键冲突错误（MySQL错误码1062），这通常意味着用户在重复点赞
    // Check for a unique key violation error (MySQL error code 1062), which usually means the user is making a duplicate like.
    if ($conn->errno == 1062) { 
        echo json_encode(['success' => false, 'message' => '你已经点过赞了！']);
    } else {
        // 对于其他类型的异常，返回通用的错误信息
        // For other types of exceptions, return a generic error message.
        echo json_encode(['success' => false, 'message' => '发生错误: ' . $e->getMessage()]);
    }
}

// 关闭数据库连接，释放资源
// Close the database connection to free up resources.
$conn->close();
?>