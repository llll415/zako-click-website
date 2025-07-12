<?php
// delete_user.php - 处理用户取消认证请求的后端脚本
// delete_user.php - Backend script to handle user self-deletion requests.

// 开启Session，用于验证用户身份
// Start the session to verify the user's identity.
session_start();

// 设置响应头为JSON格式
// Set the response header to JSON format.
header('Content-Type: application/json');

// 引入数据库配置
// Include the database connection configuration.
require_once 'db_config.php';

// 初始化响应数组
// Initialize the response array.
$response = ['success' => false, 'message' => '未知错误.'];

// 检查是否为POST请求
// Check if the request method is POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = '无效的请求方法.';
    echo json_encode($response);
    exit;
}

// 检查数据库连接
// Check the database connection.
if ($conn->connect_error) {
    $response['message'] = '数据库连接失败.';
    echo json_encode($response);
    exit;
}

// 获取从客户端发来的UUID，这是验证用户身份的关键
// Get the client UUID from the request, this is key for verification.
$client_uuid = $_POST['client_uuid'] ?? null;

// 确保关键参数存在
// Ensure crucial parameters exist.
if (empty($client_uuid)) {
    $response['message'] = '身份验证失败，无法执行操作.';
    echo json_encode($response);
    exit;
}

// 使用事务确保操作的原子性
// Use a transaction to ensure atomicity.
$conn->begin_transaction();

try {
    // --- 关键修复在这里 ---
    // --- The key fix is here ---
    // 修改: 删除语句现在只根据 client_uuid 来定位用户记录进行删除。
    // 这是更可靠的方式，因为它不依赖于可能已过期的 session_id。
    // Modified: The DELETE statement now locates the user record for deletion based only on the client_uuid.
    // This is more reliable as it doesn't depend on a potentially expired session_id.
    $stmt = $conn->prepare("DELETE FROM user_clicks WHERE client_uuid = ?");
    $stmt->bind_param("s", $client_uuid);
    $stmt->execute();

    // 检查是否有行被删除
    // Check if any row was affected.
    if ($stmt->affected_rows > 0) {
        // 如果删除成功，提交事务
        // If deletion was successful, commit the transaction.
        $conn->commit();
        
        // 清理服务器端的Session状态，让用户回到未认证状态
        // Clean up the server-side session state to revert the user to an unauthenticated state.
        unset($_SESSION['clicked']);
        session_destroy();

        $response['success'] = true;
        $response['message'] = '您的认证信息已成功删除.';
    } else {
        // 如果没有行被删除，可能是因为信息不匹配或用户不存在，回滚操作
        // If no rows were affected, it might be due to a mismatch or non-existent user. Rollback the transaction.
        throw new Exception('未找到匹配的用户记录，或您无权操作.');
    }
    
    $stmt->close();

} catch (Exception $e) {
    // 如果发生任何错误，回滚事务
    // If any error occurs, rollback the transaction.
    $conn->rollback();
    $response['message'] = '操作失败: ' . $e->getMessage();
}

// 关闭数据库连接
// Close the database connection.
$conn->close();

// 返回JSON响应
// Return the JSON response.
echo json_encode($response);

?>