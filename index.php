<?php
// 开启Session，这是所有基于Session功能的第一步，必须在任何输出之前调用
// Start the session, this is the first step for all session-based features and must be called before any output.
session_start();

// --- 辅助函数定义 ---
// --- Helper Functions Definition ---

function getFirstChar($str) {
    if (function_exists('mb_substr')) {
        return mb_substr((string)$str, 0, 1, 'UTF-8');
    }
    preg_match('/./u', (string)$str, $matches);
    return $matches[0] ?? '';
}

function getOS($userAgent) {
    $osPlatform = "未知系统";
    $osArray = [
        '/windows nt 10/i'      => 'Windows 10/11',
        '/windows nt 6.3/i'     => 'Windows 8.1',
        '/windows nt 6.2/i'     => 'Windows 8',
        '/windows nt 6.1/i'     => 'Windows 7',
        '/macintosh|mac os x/i' => 'macOS',
        '/linux/i'              => 'Linux',
        '/ubuntu/i'             => 'Ubuntu',
        '/iphone/i'             => 'iOS',
        '/ipad/i'               => 'iPadOS',
        '/android/i'            => 'Android',
    ];
    foreach ($osArray as $regex => $value) {
        if (preg_match($regex, $userAgent)) {
            $osPlatform = $value;
            break;
        }
    }
    return $osPlatform;
}

function getOSIcon($osName) {
    $osNameLower = strtolower($osName);
    if (str_contains($osNameLower, 'windows')) return 'fa-brands fa-windows';
    if (str_contains($osNameLower, 'macos')) return 'fa-brands fa-apple';
    if (str_contains($osNameLower, 'linux')) return 'fa-brands fa-linux';
    if (str_contains($osNameLower, 'ubuntu')) return 'fa-brands fa-ubuntu';
    if (str_contains($osNameLower, 'android')) return 'fa-brands fa-android';
    if (str_contains($osNameLower, 'ios') || str_contains($osNameLower, 'ipad')) return 'fa-brands fa-apple';
    return 'fa-solid fa-desktop';
}

function getRealIpAddr() {
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
      return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ip[0]);
    }
    return $_SERVER['REMOTE_ADDR'];
}

function maskIpAddress($ip) {
    $parts = explode('.', $ip);
    if (count($parts) === 4 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $parts[0] . '.xxx.xxx.' . $parts[3];
    }
    return $ip;
}

// 【新增】一个辅助函数，用于从Cookie中安全地获取客户端UUID
// 【New】A helper function to safely get the client UUID from the Cookie.
function getClientUuidFromCookie() {
    return $_COOKIE['zako_uuid'] ?? null;
}


// --- 数据库配置 ---
// --- Database Configuration ---
require_once 'db_config.php';


// --- 数据库初始化与检查 ---
// --- Database Initialization and Check ---
$dbError = ""; 
$tableExists = true; 

if ($conn->connect_error) {
    $dbError = "数据库连接失败: " . $conn->connect_error;
} else {
    $createUserTable = "CREATE TABLE IF NOT EXISTS user_clicks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(255) NOT NULL,
        client_uuid VARCHAR(36) NULL,
        nickname VARCHAR(50) NOT NULL DEFAULT '匿名Zako',
        ip_address VARCHAR(45) NOT NULL,
        user_agent VARCHAR(255) NOT NULL,
        operating_system VARCHAR(255) NOT NULL,
        ip_location VARCHAR(255) NOT NULL,
        isp VARCHAR(255) NOT NULL,
        comment TEXT,
        likes_count INT NOT NULL DEFAULT 0,
        click_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_session (session_id),
        UNIQUE KEY unique_client_uuid (client_uuid)
    )";
    if (!$conn->query($createUserTable)) {
        $dbError = "创建 'user_clicks' 表失败: " . $conn->error;
        $tableExists = false;
    }

    $createLikesTable = "CREATE TABLE IF NOT EXISTS user_likes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        liker_uuid VARCHAR(36) NOT NULL,
        liked_user_id INT NOT NULL,
        like_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_like (liker_uuid, liked_user_id),
        FOREIGN KEY (liked_user_id) REFERENCES user_clicks(id) ON DELETE CASCADE
    )";
    if ($tableExists && !$conn->query($createLikesTable)) {
        $dbError .= ($dbError ? " | " : "") . "创建 'user_likes' 表失败: " . $conn->error;
        $tableExists = false;
    }
}

// --- 业务逻辑 ---
// --- Business Logic ---
$dbWorking = $conn && !$conn->connect_error && $tableExists; 
$userCount = 0;

if ($dbWorking) {
    $usersResult = $conn->query("SELECT COUNT(id) AS user_count FROM user_clicks");
    if ($usersResult) $userCount = $usersResult->fetch_assoc()['user_count'];
}

$userHasClicked = false; 
$currentUserData = null; 
$currentClientUuid = null; // 这个变量现在会在新的识别逻辑中被赋值 / This variable will now be assigned in the new identification logic.
$likedUserIds = []; 


// --- 【重构】更健壮的用户识别与状态同步逻辑 ---
// --- 【Refactor】More Robust User Identification and State Synchronization Logic ---
if ($dbWorking) {
    $sessionId = session_id();
    $clientUuidFromCookie = getClientUuidFromCookie();
    $currentClientUuid = $clientUuidFromCookie; // 无论是否找到用户，都将从cookie获取的UUID作为当前用户的UUID / Assign the UUID from the cookie as the current user's UUID, regardless of whether a user is found.

    // 优先级1：使用从Cookie获取的、最可靠的 client_uuid 进行查找
    // Priority 1: Use the most reliable client_uuid from the Cookie to find the user.
    if ($clientUuidFromCookie) {
        $stmt_find_by_uuid = $conn->prepare("SELECT * FROM user_clicks WHERE client_uuid = ?");
        $stmt_find_by_uuid->bind_param("s", $clientUuidFromCookie);
        $stmt_find_by_uuid->execute();
        $result_by_uuid = $stmt_find_by_uuid->get_result();

        if ($result_by_uuid->num_rows > 0) {
            $userHasClicked = true;
            $currentUserData = $result_by_uuid->fetch_assoc();
            
            // 【核心修复】状态同步：如果数据库中的session_id与当前不符，立即更新它
            // 【Core Fix】State Synchronization: If the session_id in the database does not match the current one, update it immediately.
            if ($currentUserData['session_id'] !== $sessionId) {
                try {
                    $stmt_update_session = $conn->prepare("UPDATE user_clicks SET session_id = ? WHERE client_uuid = ?");
                    $stmt_update_session->bind_param("ss", $sessionId, $clientUuidFromCookie);
                    $stmt_update_session->execute();
                    $stmt_update_session->close();
                    // 更新PHP变量中的数据以保持一致
                    // Update the data in the PHP variable to maintain consistency.
                    $currentUserData['session_id'] = $sessionId;
                } catch(Exception $e) {
                    // 如果因为unique_session键冲突而更新失败，说明新的session_id已被其他记录占用，这不是一个致命错误，可以忽略
                    // If the update fails due to a unique_session key conflict, it means the new session_id is already taken by another record. This is not a fatal error and can be ignored.
                }
            }
        }
        $stmt_find_by_uuid->close();
    }

    // 优先级2：如果通过UUID没找到用户（例如，cookie丢失或首次访问），再尝试使用临时的 session_id 查找
    // Priority 2: If no user is found by UUID (e.g., cookie lost or first visit), then try to find by the temporary session_id.
    if (!$userHasClicked) {
        $stmt_find_by_session = $conn->prepare("SELECT * FROM user_clicks WHERE session_id = ?");
        $stmt_find_by_session->bind_param("s", $sessionId);
        $stmt_find_by_session->execute();
        $result_by_session = $stmt_find_by_session->get_result();
        if ($result_by_session->num_rows > 0) {
            $userHasClicked = true;
            $currentUserData = $result_by_session->fetch_assoc();
            // 如果通过session找到了用户，但cookie是空的，将数据库的UUID赋给当前UUID
            // If user found by session but cookie is empty, assign the UUID from DB to the current UUID.
            if (!$currentClientUuid && !empty($currentUserData['client_uuid'])) {
                $currentClientUuid = $currentUserData['client_uuid'];
            }
        }
        $stmt_find_by_session->close();
    }
    
    // 标记会话状态
    // Mark the session state.
    if ($userHasClicked) {
        $_SESSION['clicked'] = true;
    } else {
        unset($_SESSION['clicked']);
    }

    // 如果用户已认证，获取他们点过赞的所有用户ID
    // If the user is authenticated, get all the user IDs they have liked.
    if ($userHasClicked && $currentClientUuid) {
        $stmt_likes = $conn->prepare("SELECT liked_user_id FROM user_likes WHERE liker_uuid = ?");
        $stmt_likes->bind_param("s", $currentClientUuid);
        $stmt_likes->execute();
        $result_likes = $stmt_likes->get_result();
        while ($row = $result_likes->fetch_assoc()) {
            $likedUserIds[] = $row['liked_user_id'];
        }
        $stmt_likes->close();
    }
}


// 处理用户“认证成为zako”的POST请求
// Handle the POST request for "certify as zako".
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['click']) && !$userHasClicked) {
    if ($dbWorking) {
        $sessionId = session_id();
        $ipAddress = getRealIpAddr();
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) { $ipAddress = 'Invalid IP'; }
        
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        $os = getOS($userAgent);
        
        $clientUuid = $_POST['client_uuid'] ?? null;
        if (empty($clientUuid)) {
             $dbError = "客户端标识丢失，无法认证。请确保浏览器支持 LocalStorage 且未被禁用。";
        } else {
            $location = "未知归属地";
            $isp = "未知运营商";
            $defaultNickname = "一位匿名Zako"; 

            if ($ipAddress && filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $apiUrl = "https://ip9.com.cn/get?ip={$ipAddress}";
                $apiResponse = @file_get_contents($apiUrl);
                if ($apiResponse) {
                    $data = json_decode($apiResponse, true);
                    if ($data && isset($data['ret']) && $data['ret'] == 200 && isset($data['data'])) {
                        $prov = $data['data']['prov'] ?? '';
                        $city = $data['data']['city'] ?? '';
                        if (!empty($prov)) {
                            $defaultNickname = "来自{$prov}";
                            if (!empty($city)) { $defaultNickname .= $city; }
                            $defaultNickname .= "的Zako";
                        }
                        $location = trim("{$data['data']['country']} {$prov} {$city} {$data['data']['area']}");
                        if (empty($location)) { $location = "未知归属地"; }
                        $isp = $data['data']['isp'] ?? '未知运营商';
                    }
                }
            } else if (filter_var($ipAddress, FILTER_VALIDATE_IP)) {
                 $location = "内网地址";
                 $isp = "未知运营商";
            }
            
            try {
                $stmt = $conn->prepare("INSERT INTO user_clicks (session_id, client_uuid, nickname, ip_address, user_agent, operating_system, ip_location, isp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssss", $sessionId, $clientUuid, $defaultNickname, $ipAddress, $userAgent, $os, $location, $isp);
                if ($stmt->execute()) {
                    $_SESSION['clicked'] = true;
                    $userHasClicked = true;
                    $userCount++;
                }
            } catch (Exception $e) { 
                if ($conn->errno == 1062) {
                    $userHasClicked = true;
                    $_SESSION['clicked'] = true;
                } else {
                    $dbError = "记录点击失败: " . $e->getMessage();
                }
            }
        }
    } else { $dbError = "数据库不可用，无法记录点击"; }
    
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

// --- 视图模式选择 ---
// --- View Mode Selection ---
$viewMode = $_GET['view'] ?? 'default';

// --- 数据查询 ---
// --- Data Query ---
$recentUsers = [];
$allUsers = [];
if ($dbWorking) {
    $fields = "id, session_id, client_uuid, nickname, ip_address, click_time, operating_system, ip_location, isp, comment, likes_count";
    if ($viewMode === 'all') {
        $result = $conn->query("SELECT $fields FROM user_clicks ORDER BY click_time DESC");
        if ($result) {
            $allUsers = $result->fetch_all(MYSQLI_ASSOC);
        }
    } else {
        $recentUsers = $conn->query("SELECT $fields FROM user_clicks ORDER BY click_time DESC LIMIT 5");
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zako人数统计</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @font-face { font-family: '萝莉体 第二版'; src: url('萝莉体_第二版.woff2') format('woff2'); font-weight: normal; font-style: normal; font-display: swap; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: '萝莉体 第二版', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        html { scroll-behavior: smooth; }
        body { background-image: url('https://imageapi.hoshino2.top/pc'); background-size: cover; background-position: center center; background-attachment: fixed; background-color: #fdf6f8; padding: 0 15px; }
        .container { background-color: rgba(255, 255, 255, 0.25); backdrop-filter: blur(2px) saturate(150%); border-radius: 25px; box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.1); border: 1px solid rgba(255, 255, 255, 0.4); padding: 40px; width: 100%; max-width: 700px; text-align: center; position: relative; z-index: 2; margin: 5vh auto; }
        .header { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.2); }
        h1 { color: #2c3e50; font-size: 2.8rem; margin-bottom: 10px; display: flex; align-items: center; justify-content: center; gap: 15px; text-shadow: 1px 1px 3px rgba(0,0,0,0.1); }
        .subtitle { color: #556677; font-size: 1.3rem; font-weight: 400; max-width: 500px; margin: 0 auto; }
        .counter-container { position: relative; margin: 40px auto; padding: 30px; width: 90%; display: flex; flex-direction: column; justify-content: center; align-items: center; border-radius: 20px; background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(255, 255, 255, 0.5); box-shadow: 0 8px 25px rgba(0,0,0,0.05); color: #2c3e50; overflow: hidden; }
        .counter-label { font-size: 1.5rem; margin-bottom: 15px; font-weight: 500; color: #34495e; }
        .counter-value { font-size: 4.5rem; font-weight: bold; color: #2c3e50; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.05); } 100% { transform: scale(1); } }
        .btn-container { margin: 40px 0 30px; display: flex; justify-content: center; }
        .click-btn { background: linear-gradient(45deg, #ff6b6b, #ff8e53); border: none; border-radius: 50px; color: white; font-size: 1.6rem; font-weight: bold; padding: 20px 60px; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 10px 25px rgba(255, 107, 107, 0.3); position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1); background-size: 200% auto; cursor: pointer; }
        .click-btn:hover:not(:disabled) { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(255, 107, 107, 0.4); background-position: right center; }
        .click-btn:active:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(255, 107, 107, 0.35); }
        .click-btn:disabled { background: linear-gradient(45deg, #95a5a6, #bdc3c7); box-shadow: 0 5px 15px rgba(149, 165, 166, 0.2); cursor: not-allowed; }
        .status-container { display: flex; justify-content: center; gap: 20px; margin-top: 25px; flex-wrap: wrap; }
        .status-card { background: rgba(255, 255, 255, 0.6); border-radius: 12px; padding: 15px 20px; min-width: 200px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); border: 1px solid rgba(255, 255, 255, 0.6); flex-grow: 1; }
        .status-title { color: #556677; font-size: 0.95rem; margin-bottom: 8px; }
        .status-value { font-size: 1.2rem; font-weight: bold; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .status-value.db-active { color: #27ae60; } .status-value.db-inactive { color: #e74c3c; } .status-value.user-active { color: #3498db; } .status-value.user-inactive { color: #f39c12; }
        .recent-panel { background-color: rgba(255, 255, 255, 0.5); border-radius: 20px; padding: 25px; margin-top: 30px; text-align: left; box-shadow: 0 8px 20px rgba(0,0,0,0.05); border: 1px solid rgba(255, 255, 255, 0.3); }
        .panel-title { color: #2c3e50; margin-bottom: 20px; font-size: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;}
        .panel-title-actions { display: flex; gap: 10px; }
        .edit-nickname-trigger { background: #3498db; color: white; border: none; border-radius: 8px; padding: 5px 10px; font-size: 0.9rem; cursor: pointer; transition: background 0.3s; }
        .edit-nickname-trigger:hover { background: #2980b9; }
        .delete-user-trigger {
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 5px 10px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: background 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .delete-user-trigger:hover {
            background: #c0392b;
        }
        .recent-users { display: flex; flex-direction: column; gap: 15px; }
        .user-card { 
            background: rgba(255, 255, 255, 0.6); 
            border-radius: 12px; 
            padding: 15px; 
            box-shadow: 0 3px 10px rgba(0,0,0,0.05); 
            display: flex; 
            align-items: flex-start;
            gap: 15px; 
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .user-card:hover { transform: translateY(-3px) scale(1.02); box-shadow: 0 6px 15px rgba(0,0,0,0.08); }
        .user-avatar { width: 45px; height: 45px; background: linear-gradient(135deg, #3498db, #9b59b6); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.8rem; font-weight: bold; flex-shrink: 0; }
        .user-details-column {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .user-nickname { font-weight: bold; color: #2c3e50; margin-bottom: 4px; font-size: 1.1rem; }
        .you-indicator {
            color: #27ae60;
            font-size: 0.9em;
            font-weight: bold;
        }
        .user-ip { font-size: 0.9rem; color: #556677; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-location, .user-os { color: #556677; font-size: 0.9rem; margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
        .user-time { color: #7f8c8d; font-size: 0.85rem; margin-top: 4px; }
        .user-comment-column {
            flex-basis: 45%; 
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .user-comment {
            flex-grow: 1;
            padding: 10px; 
            background: rgba(0,0,0,0.05); 
            border-radius: 8px; 
            font-size: 0.95rem; 
            color: #34495e; 
            border-left: 3px solid #3498db; 
            word-break: break-all;
            min-height: 50px;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
        }
        .user-comment.editable { cursor: pointer; }
        .user-comment.editable:hover { background: rgba(0,0,0,0.08); }
        .user-comment-placeholder { font-style: italic; color: #95a5a6; border-left-color: #bdc3c7; }
        .comment-editor { width: 100%; }
        .comment-editor-textarea {
            width: 100%;
            height: 80px;
            padding: 8px;
            border-radius: 6px;
            border: 1px solid #bdc3c7;
            font-size: 0.95rem;
            resize: vertical;
        }
        .comment-editor-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 5px; }
        .comment-char-counter { font-size: 0.85rem; color: #556677; }
        .comment-editor-buttons button {
            border: none;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: background 0.2s;
            margin-left: 5px;
        }
        .comment-save-btn { background: #27ae60; color: white; }
        .comment-save-btn:hover { background: #2ecc71; }
        .comment-cancel-btn { background: #e74c3c; color: white; }
        .comment-cancel-btn:hover { background: #c0392b; }
        .user-avatar-container { display: flex; flex-direction: column; align-items: center; gap: 8px; flex-shrink: 0; }
        .like-section { display: flex; align-items: center; justify-content: center; gap: 5px; color: #e74c3c; }
        .like-btn { background: none; border: none; cursor: pointer; color: #e74c3c; font-size: 1.1rem; padding: 2px; transition: transform 0.2s, color 0.2s; }
        .like-btn:hover:not(:disabled) { transform: scale(1.2); }
        .like-btn .fa-solid { color: #e74c3c; } 
        .like-btn .fa-regular { color: #7f8c8d; } 
        .like-btn:disabled { cursor: not-allowed; }
        .like-btn:disabled .fa-regular { color: #bdc3c7; } 
        .like-count { font-size: 0.9rem; font-weight: bold; }
        .like-animation { animation: like-pop 0.4s ease-out; }
        @keyframes like-pop { 0% { transform: scale(1); } 50% { transform: scale(1.4); } 100% { transform: scale(1); } }
        .error-panel { background-color: rgba(255, 235, 238, 0.9); border-left: 4px solid #f44336; border-radius: 8px; padding: 12px 15px; margin-bottom: 20px; text-align: left; }
        .error-title { color: #f44336; font-weight: bold; margin-bottom: 5px; display: flex; align-items: center; gap: 8px; }
        footer { margin-top: 30px; color: #333; font-size: 0.9rem; text-align: center; text-shadow: 0px 1px 2px rgba(255, 255, 255, 0.7); }
        .click-text-pop, .mouse-trail, .particle { pointer-events: none; }
        .click-text-pop { position: fixed; font-size: 24px; color: #ff6b6b; font-weight: bold; text-shadow: 1px 1px 3px rgba(0,0,0,0.3); user-select: none; z-index: 99999; opacity: 0; animation: text-pop-animation 0.8s ease-out forwards; }
        @keyframes text-pop-animation { 0% { transform: translate(-50%, -50%) scale(0.5); opacity: 1; } 100% { transform: translate(-50%, -150%) scale(1.2); opacity: 0; } }
        .mouse-trail { position: fixed; width: 12px; height: 12px; background: #ff8e53; border-radius: 50%; transform: translate(-50%, -50%); z-index: 99998; animation: trail-fade 500ms linear forwards; }
        @keyframes trail-fade { to { width: 0; height: 0; opacity: 0; } }
        .particle { position: fixed; background-color: #f00; border-radius: 50%; z-index: 99997; width: 8px; height: 8px; animation: firework-explode 700ms ease-out forwards; }
        @keyframes firework-explode { from { transform: translate(-50%, -50%) scale(1); opacity: 1; } to { transform: translate(var(--end-x-rel), var(--end-y-rel)) scale(0); opacity: 0; } }
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-content { background-color: rgba(255, 255, 255, 0.8); backdrop-filter: blur(10px); padding: 30px; border-radius: 20px; width: 90%; max-width: 400px; text-align: center; transform: scale(0.9); transition: transform 0.3s; }
        .modal-overlay.active .modal-content { transform: scale(1); }
        .modal-content h3 { margin-bottom: 20px; color: #2c3e50; }
        .modal-input { width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ccc; font-size: 1rem; margin-bottom: 20px; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; }
        .modal-btn { padding: 10px 20px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; transition: background 0.3s; }
        .modal-btn-save { background: #27ae60; color: white; }
        .modal-btn-save:hover { background: #2ecc71; }
        .modal-btn-cancel { background: #e74c3c; color: white; }
        .modal-btn-cancel:hover { background: #c0392b; }
        .view-all-container { margin-top: 30px; }
        .view-all-btn, .back-btn { display: inline-block; background: #3498db; color: white; padding: 12px 25px; border-radius: 10px; text-decoration: none; font-size: 1.1rem; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3); }
        .view-all-btn:hover, .back-btn:hover { background: #2980b9; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(52, 152, 219, 0.4); }
        .back-btn { font-size: 0.9rem; padding: 8px 15px; }
        .all-users-container { max-width: 900px; text-align: left; }
        .all-users-table-wrapper { width: 100%; overflow-x: auto; margin-top: 20px; }
        .all-users-table { width: 100%; border-collapse: collapse; }
        .all-users-table th, .all-users-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid rgba(0,0,0,0.1); }
        .all-users-table th { background: rgba(255,255,255,0.4); color: #34495e; font-size: 1rem; }
        .all-users-table td { background: rgba(255,255,255,0.2); font-size: 0.95rem; vertical-align: middle;}
        .all-users-table tr:hover td { background: rgba(255,255,255,0.5); }
        .all-users-table .user-avatar { margin: 0 auto; }
        .all-users-table .user-ip-details { font-size: 0.85rem; color: #556677; display: block; }
        .all-users-table .user-os-icon { width: 18px; text-align: center; }
        .all-users-table .comment-cell { max-width: 250px; white-space: normal; word-break: break-word; }
        @media (max-width: 768px) {
            body { background-image: url('https://imageapi.hoshino2.top/mobile'); padding: 0 10px; }
            .container { padding: 20px; margin: 20px auto; }
            h1 { font-size: 2.2rem; } .subtitle { font-size: 1.1rem; } .counter-value { font-size: 3.5rem; } .click-btn { padding: 15px 30px; font-size: 1.2rem; } .status-card { min-width: 150px; } .recent-panel { padding: 20px; } .panel-title { font-size: 1.3rem; }
            .user-card { flex-direction: column; align-items: stretch; }
            .user-avatar-container { flex-direction: row; justify-content: space-between; align-items: center; margin-bottom: 10px; }
            .user-comment-column { flex-basis: auto; width: 100%; margin-top: 10px;}
            .all-users-table thead { display: none; }
            .all-users-table, .all-users-table tbody, .all-users-table tr, .all-users-table td { display: block; width: 100%; }
            .all-users-table tr { margin-bottom: 15px; border: 1px solid rgba(0,0,0,0.1); border-radius: 10px; overflow: hidden; }
            .all-users-table td { text-align: right; padding-left: 50%; position: relative; border-bottom: 1px solid rgba(0,0,0,0.05); }
            .all-users-table td:before { content: attr(data-label); position: absolute; left: 10px; width: 45%; padding-right: 10px; white-space: nowrap; text-align: left; font-weight: bold; color: #34495e; }
        }
        @media (min-width: 992px) { .container { max-width: 800px; } .all-users-container { max-width: 1000px;} .status-container { gap: 30px; } }
    </style>
</head>
<body>
    <?php if ($viewMode === 'default'): ?>
    <div class="container">
        <div class="header"><h1><i class="fas fa-users"></i>zako人数统计</h1><p class="subtitle">点击下方按钮认证成为zako，每位用户仅限点击一次</p></div>
        <div class="subtitle">注意:认证后您的IP地址将会存进本站数据库 仅供他人得知你的归属地</p></div>
        
        <?php if (!empty($dbError)): ?><div class="error-panel"><div class="error-title"><i class="fas fa-exclamation-triangle"></i>操作限制</div><p><?php echo htmlspecialchars($dbError); ?></p></div><?php endif; ?>
        
        <div class="counter-container"><div class="counter-label">zako人数</div><div class="counter-value"><?php echo $userCount; ?></div></div>
        
        <?php if (!$userHasClicked): ?>
            <div class="btn-container">
                <form method="post" id="click-form">
                    <input type="hidden" id="client-uuid-input" name="client_uuid" value="">
                    <button class="click-btn" type="submit" name="click" id="click-btn" <?php if (!$dbWorking) echo 'disabled'; ?>><i class="fas fa-hand-pointer"></i> 认证成为zako</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="status-container">
            <div class="status-card"><div class="status-title">数据库状态</div><div class="status-value <?php echo $dbWorking ? 'db-active' : 'db-inactive'; ?>"><i class="fas fa-<?php echo $dbWorking ? 'check-circle' : 'exclamation-circle'; ?>"></i><?php echo $dbWorking ? '正常运行' : '不可用'; ?></div></div>
            <div class="status-card"><div class="status-title">您的参与状态</div><div class="status-value <?php echo $userHasClicked ? 'user-inactive' : 'user-active'; ?>"><i class="fas fa-<?php echo $userHasClicked ? 'check-circle' : 'user-clock'; ?>"></i><?php if ($userHasClicked) echo '已认证成为zako'; elseif (!$dbWorking) echo '数据库不可用'; else echo '未认证成为zako'; ?></div></div>
        </div>
        
        <?php if ($dbWorking && $recentUsers): ?>
        <div class="recent-panel">
            <div class="panel-title">
                <span><i class="fas fa-history"></i> 最近认证的Zako们~ </span>
                <div class="panel-title-actions">
                    <?php if ($userHasClicked): ?>
                        <button id="delete-user-trigger" class="delete-user-trigger"><i class="fas fa-user-slash"></i> 取消认证</button>
                        <button id="edit-nickname-trigger" class="edit-nickname-trigger"><i class="fas fa-pencil-alt"></i> 修改昵称</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="recent-users">
                <?php if ($recentUsers->num_rows > 0): ?>
                    <?php while($user = $recentUsers->fetch_assoc()): ?>
                        <div class="user-card" data-user-id="<?php echo $user['id']; ?>">
                            <div class="user-avatar-container">
                                <div class="user-avatar"><?php echo htmlspecialchars(getFirstChar($user['nickname'])); ?></div>
                                <div class="like-section">
                                <?php
                                    $isCurrentUserPost = ($currentClientUuid && $currentClientUuid === $user['client_uuid']);
                                    $hasLiked = in_array($user['id'], $likedUserIds);
                                    $canLike = $userHasClicked && !$isCurrentUserPost;
                                ?>
                                <button class="like-btn" 
                                        data-user-id="<?php echo $user['id']; ?>" 
                                        <?php if (!$canLike || $hasLiked) echo 'disabled'; ?>
                                        title="<?php echo $isCurrentUserPost ? '不能给自己点赞' : ($hasLiked ? '已赞过' : '点赞'); ?>">
                                    <i class="<?php echo ($hasLiked || $isCurrentUserPost) ? 'fa-solid' : 'fa-regular'; ?> fa-heart"></i>
                                </button>
                                <span class="like-count"><?php echo $user['likes_count']; ?></span>
                                </div>
                            </div>
                            
                            <div class="user-details-column">
                                <div class="user-nickname">
                                    <?php 
                                        echo htmlspecialchars($user['nickname']); 
                                        // 【修复】判断是否为“你”的逻辑现在更可靠，直接使用$currentClientUuid进行比较
                                        // 【Fix】The logic to determine if it is "you" is now more reliable, comparing directly with $currentClientUuid.
                                        if ($userHasClicked && $currentClientUuid && $user['client_uuid'] === $currentClientUuid) {
                                            echo ' <span class="you-indicator">(你)</span>';
                                        }
                                    ?>
                                </div>
                                <div class="user-ip"><i class="fas fa-network-wired"></i> <?php echo htmlspecialchars(maskIpAddress($user['ip_address'])); ?></div>
                                <div class="user-location"><i class="fas fa-map-marker-alt"></i><span> <?php echo htmlspecialchars($user['ip_location']); ?> | <i class="fas fa-broadcast-tower"></i> <?php echo htmlspecialchars($user['isp']); ?></span></div>
                                <div class="user-os"><i class="<?php echo getOSIcon($user['operating_system']); ?>"></i><span> <?php echo htmlspecialchars($user['operating_system']); ?></span></div>
                                <div class="user-time"><?php echo date('Y-m-d H:i', strtotime($user['click_time'])); ?></div>
                            </div>

                            <div class="user-comment-column">
                                <?php 
                                    // 【修复】判断评论是否可编辑的逻辑也使用$currentClientUuid
                                    // 【Fix】The logic to determine if a comment is editable also uses $currentClientUuid.
                                    $isCurrentUserEditable = $userHasClicked && $currentClientUuid && $user['client_uuid'] === $currentClientUuid; 
                                ?>
                                <div class="user-comment <?php if ($isCurrentUserEditable) echo 'editable'; ?>" 
                                     data-current-comment="<?php echo htmlspecialchars($user['comment'] ?? ''); ?>">
                                    <?php 
                                    if (!empty($user['comment'])) {
                                        echo '<i class="fas fa-quote-left" style="font-size: 0.8em; margin-right: 5px; opacity: 0.6;"></i>' . htmlspecialchars($user['comment']);
                                    } elseif ($isCurrentUserEditable) {
                                        echo '<span class="user-comment-placeholder">点击这里留下留言... (100字以内)</span>';
                                    } else {
                                        echo '<span class="user-comment-placeholder">这个人很zako 什么都没写</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="text-align: center; padding: 15px; color: #556677;"><i class="fas fa-info-circle"></i> 暂无参与记录</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($dbWorking): ?>
        <div class="view-all-container">
            <a href="?view=all" class="view-all-btn"><i class="fas fa-list-ul"></i> 查看所有已认证的Zako~</a>
        </div>
        <?php endif; ?>

		<footer><p>若您需要删除(取消认证按钮无效)可联系nahidallkkookk@gmail.com 本站存储的IP地址仅供他人了解你的归属地</p>
        <footer><p>使用 PHP + MySQL 构建|代码部分由Deepseek+Gemini完成</p><p>当前会话ID: <?php echo substr(session_id(), 0, 12); ?>...</p></footer>
        <a href="https://github.com/llll415/zako-click-website">源代码已开放-仓库:https://github.com/llll415/zako-click-website</a>
    </div>
    
    <?php else: ?>
    <!-- ======================= 全员列表视图 / All Users List View ======================= -->
    <div class="container all-users-container">
        <div class="panel-title">
            <span><i class="fas fa-users"></i> 总共有 <?php echo count($allUsers); ?> 位已认证的Zako~</span>
            <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i> 返回主页</a>
        </div>
        <div class="all-users-table-wrapper">
            <table class="all-users-table">
                <thead>
                    <tr>
                        <th style="width:80px; text-align:center;">头像/点赞</th>
                        <th>昵称</th>
                        <th>IP / 地点 / ISP</th>
                        <th>操作系统</th>
                        <th>留言</th>
                        <th>认证时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($allUsers) > 0): ?>
                        <?php foreach($allUsers as $user): ?>
                        <tr>
                            <td data-label="头像/点赞">
                                <div class="user-avatar-container" style="justify-content: center;">
                                    <div class="user-avatar"><?php echo htmlspecialchars(getFirstChar($user['nickname'])); ?></div>
                                    <div class="like-section">
                                    <?php
                                        $isCurrentUserPost = ($currentClientUuid && $currentClientUuid === $user['client_uuid']);
                                        $hasLiked = in_array($user['id'], $likedUserIds);
                                        $canLike = $userHasClicked && !$isCurrentUserPost;
                                    ?>
                                    <button class="like-btn" 
                                            data-user-id="<?php echo $user['id']; ?>" 
                                            <?php if (!$canLike || $hasLiked) echo 'disabled'; ?>
                                            title="<?php echo $isCurrentUserPost ? '不能给自己点赞' : ($hasLiked ? '已赞过' : '点赞'); ?>">
                                        <i class="<?php echo ($hasLiked || $isCurrentUserPost) ? 'fa-solid' : 'fa-regular'; ?> fa-heart"></i>
                                    </button>
                                    <span class="like-count"><?php echo $user['likes_count']; ?></span>
                                    </div>
                                </div>
                            </td>
                            <td data-label="昵称">
                                <?php 
                                    echo htmlspecialchars($user['nickname']); 
                                    if ($userHasClicked && $currentClientUuid && $user['client_uuid'] === $currentClientUuid) {
                                        echo ' <span class="you-indicator">(你)</span>';
                                    }
                                ?>
                            </td>
                            <td data-label="IP详情"><?php echo htmlspecialchars(maskIpAddress($user['ip_address'])); ?><span class="user-ip-details"><?php echo htmlspecialchars($user['ip_location']); ?> | <?php echo htmlspecialchars($user['isp']); ?></span></td>
                            <td data-label="系统"><i class="<?php echo getOSIcon($user['operating_system']); ?> user-os-icon"></i> <?php echo htmlspecialchars($user['operating_system']); ?></td>
                            <td data-label="留言" class="comment-cell">
                                <?php
                                if (!empty($user['comment'])) {
                                    echo htmlspecialchars($user['comment']);
                                } else {
                                    echo '<span class="user-comment-placeholder">这个人很zako 什么都没写</span>';
                                }
                                ?>
                            </td>
                            <td data-label="认证时间"><?php echo date('Y-m-d H:i', strtotime($user['click_time'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align:center; padding: 20px;">暂无参与记录</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>


    <!-- 昵称修改弹窗 / Nickname Edit Modal -->
    <div id="nickname-modal-overlay" class="modal-overlay">
        <div class="modal-content">
            <h3>修改你的昵称</h3>
            <input type="text" id="modal-nickname-input" class="modal-input" placeholder="输入新昵称...">
            <div class="modal-actions">
                <button id="modal-cancel-btn" class="modal-btn modal-btn-cancel">取消</button>
                <button id="modal-save-btn" class="modal-btn modal-btn-save">保存</button>
            </div>
        </div>
    </div>

    <!-- 音频元素，用于点击音效 / Audio element for click sound effect -->
    <audio id="click-audio" src="zako.mp3" preload="auto"></audio>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- 客户端唯一标识符 (UUID) 管理 ---
        // --- Client-side Unique Identifier (UUID) Management ---
        const uuidInput = document.getElementById('client-uuid-input');
        let clientUUID = null; 
        
        function generateUUIDv4() {
            return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
                (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
            );
        }

        // 【新增】设置Cookie的函数
        // 【New】Function to set a cookie.
        function setCookie(name, value, days) {
            let expires = "";
            if (days) {
                const date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = "; expires=" + date.toUTCString();
            }
            // 设置一个长期的、全站有效的cookie
            // Set a long-term, site-wide cookie.
            document.cookie = name + "=" + (value || "")  + expires + "; path=/; SameSite=Lax";
        }

        /**
         * 【修改】获取或生成客户端UUID，并同时存入LocalStorage和Cookie
         * 【Modified】Gets or generates the client UUID and stores it in both LocalStorage and a Cookie.
         */
        function getOrSetClientUUID() {
            clientUUID = localStorage.getItem('zako_uuid');
            if (!clientUUID) {
                clientUUID = generateUUIDv4();
                localStorage.setItem('zako_uuid', clientUUID);
            }
            // 将UUID设置到Cookie中，以便PHP后端可以读取它
            // Set the UUID in a cookie so the PHP backend can read it.
            setCookie('zako_uuid', clientUUID, 365); 

            if(uuidInput) {
                uuidInput.value = clientUUID;
            }
        }
        
        getOrSetClientUUID(); // 页面加载时立即执行 / Execute immediately on page load.

        // --- 点赞功能的客户端逻辑 (无变动) ---
        // --- Client-side logic for the like feature (no changes) ---
        document.body.addEventListener('click', function(e) {
            const likeBtn = e.target.closest('.like-btn');
            if (!likeBtn || likeBtn.disabled) {
                return;
            }
            e.preventDefault();
            likeBtn.disabled = true;
            const userIdToLike = likeBtn.dataset.userId;
            const likeIcon = likeBtn.querySelector('i');
            const likeCountSpan = likeBtn.nextElementSibling;
            const formData = new FormData();
            formData.append('user_id', userIdToLike);
            formData.append('client_uuid', clientUUID);
            fetch('like_post.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    likeCountSpan.textContent = data.new_count;
                    likeIcon.classList.remove('fa-regular');
                    likeIcon.classList.add('fa-solid');
                    likeIcon.parentElement.classList.add('like-animation');
                } else {
                    alert(data.message || '点赞失败，请稍后再试。');
                    if(data.message !== '你已经点过赞了！' && data.message !== '你不能给自己点赞哦！') {
                        likeBtn.disabled = false;
                    }
                }
            })
            .catch(error => {
                console.error('Like Error:', error);
                alert('点赞时发生网络错误。');
                likeBtn.disabled = false;
            });
        });
        
        // --- 取消认证功能的客户端逻辑 (包含强制刷新修复) ---
        // --- Client-side logic for the cancel certification feature (includes force-reload fix) ---
        const deleteUserBtn = document.getElementById('delete-user-trigger');
        if (deleteUserBtn) {
            deleteUserBtn.addEventListener('click', () => {
                const isConfirmed = confirm('您确定要取消认证吗？\n此操作将永久删除您的所有数据，且无法撤销。');
                if (isConfirmed) {
                    const formData = new FormData();
                    formData.append('client_uuid', clientUUID);
                    fetch('delete_user.php', { method: 'POST', body: formData })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('您的认证信息已删除。');
                            location.reload(true); // 使用 true 参数强制从服务器刷新 / Use the true parameter to force a reload from the server.
                        } else {
                            alert('操作失败: ' + (data.message || '未知错误'));
                        }
                    })
                    .catch(error => {
                        console.error('Delete User Error:', error);
                        alert('取消认证时发生网络错误。');
                    });
                }
            });
        }

        // --- 其他原有JS代码 (无变动) ---
        // --- Other original JS code (no changes) ---
        const fireworkColors = ['#ff6b6b', '#ff8e53', '#feca57', '#48dbfb', '#1dd1a1', '#ff9ff3'];
        const clickSound = document.getElementById('click-audio');
        const clickBtn = document.getElementById('click-btn');
        function createFireworks(x, y) { for (let i = 0; i < 20; i++) { const particle = document.createElement('div'); particle.className = 'particle'; particle.style.left = `${x}px`; particle.style.top = `${y}px`; particle.style.backgroundColor = fireworkColors[Math.floor(Math.random() * fireworkColors.length)]; const angle = Math.random() * Math.PI * 2; const distance = Math.random() * 80 + 50; const endX_relative = Math.cos(angle) * distance; const endY_relative = Math.sin(angle) * distance - 50; particle.style.setProperty('--end-x-rel', `${endX_relative}px`); particle.style.setProperty('--end-y-rel', `${endY_relative}px`); document.body.appendChild(particle); setTimeout(() => particle.remove(), 700); } }
        document.addEventListener('mousemove', function(e) { const trail = document.createElement('div'); trail.className = 'mouse-trail'; document.body.appendChild(trail); trail.style.left = e.clientX + 'px'; trail.style.top = e.clientY + 'px'; setTimeout(() => trail.remove(), 500); });
        document.addEventListener('mousedown', function(e) { if (e.target.closest('.click-btn, a, .edit-nickname-trigger, .delete-user-trigger, .modal-overlay, .user-comment.editable, .like-btn')) { return; } if (clickSound) { clickSound.currentTime = 0; clickSound.play().catch(e => {}); } const textPop = document.createElement('div'); textPop.innerHTML = '杂鱼~♡ 杂鱼~♡'; textPop.classList.add('click-text-pop'); document.body.appendChild(textPop); textPop.style.left = `${e.clientX}px`; textPop.style.top = `${e.clientY}px`; setTimeout(() => textPop.remove(), 800); createFireworks(e.clientX, e.clientY); });
        if (clickBtn) { clickBtn.addEventListener('mousedown', function() { if (clickSound) { clickSound.currentTime = 0; clickSound.play().catch(e => {}); } }); }
        const nicknameModalOverlay = document.getElementById('nickname-modal-overlay');
        const editNicknameTriggerBtn = document.getElementById('edit-nickname-trigger');
        const nicknameCancelBtn = document.getElementById('modal-cancel-btn');
        const nicknameSaveBtn = document.getElementById('modal-save-btn');
        const nicknameInput = document.getElementById('modal-nickname-input');
        if (editNicknameTriggerBtn) { editNicknameTriggerBtn.addEventListener('click', () => { nicknameModalOverlay.classList.add('active'); nicknameInput.focus(); }); }
        function closeNicknameModal() { nicknameModalOverlay.classList.remove('active'); }
        if(nicknameCancelBtn) nicknameCancelBtn.addEventListener('click', closeNicknameModal);
        if(nicknameModalOverlay) nicknameModalOverlay.addEventListener('click', (e) => { if (e.target === nicknameModalOverlay) closeNicknameModal(); });
        if(nicknameSaveBtn) { nicknameSaveBtn.addEventListener('click', function() { const newNickname = nicknameInput.value; if (!newNickname.trim()) { alert('昵称不能为空哦！'); return; } const formData = new FormData(); formData.append('nickname', newNickname); fetch('update_name.php', { method: 'POST', body: formData }).then(response => response.json()).then(data => { if (data.success) { location.reload(); } else { alert('修改失败: ' + (data.message || '未知错误')); } }).catch(error => alert('发生了一个网络或脚本错误。')); }); }
        const editableComment = document.querySelector('.user-comment.editable');
        if (editableComment) {
            const commentColumn = editableComment.parentElement;
            const originalContent = editableComment.innerHTML;
            const originalCommentText = editableComment.dataset.currentComment;
            editableComment.addEventListener('click', function turnToEditor(event) {
                if (commentColumn.querySelector('.comment-editor')) return;
                const editorWrapper = document.createElement('div');
                editorWrapper.className = 'comment-editor';
                const textarea = document.createElement('textarea');
                textarea.className = 'comment-editor-textarea';
                textarea.value = originalCommentText;
                textarea.maxLength = 100;
                const actionsDiv = document.createElement('div');
                actionsDiv.className = 'comment-editor-actions';
                const charCounter = document.createElement('span');
                charCounter.className = 'comment-char-counter';
                charCounter.textContent = `${originalCommentText.length}/100`;
                const buttonsDiv = document.createElement('div');
                buttonsDiv.className = 'comment-editor-buttons';
                const saveBtn = document.createElement('button');
                saveBtn.className = 'comment-save-btn';
                saveBtn.textContent = '保存';
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'comment-cancel-btn';
                cancelBtn.textContent = '取消';
                buttonsDiv.append(cancelBtn, saveBtn);
                actionsDiv.append(charCounter, buttonsDiv);
                editorWrapper.append(textarea, actionsDiv);
                commentColumn.innerHTML = '';
                commentColumn.appendChild(editorWrapper);
                textarea.focus();
                textarea.addEventListener('input', () => { charCounter.textContent = `${textarea.value.length}/100`; });
                cancelBtn.addEventListener('click', () => { commentColumn.innerHTML = ''; commentColumn.appendChild(editableComment); });
                saveBtn.addEventListener('click', () => {
                    const newComment = textarea.value;
                    if (newComment.length > 100) { alert('留言不能超过100个字符哦！'); return; }
                    const formData = new FormData();
                    formData.append('comment', newComment);
                    saveBtn.textContent = '保存中...';
                    saveBtn.disabled = true;
                    fetch('update_comment.php', { method: 'POST', body: formData })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) { location.reload(); } else {
                            alert('更新留言失败: ' + (data.message || '未知错误'));
                            saveBtn.textContent = '保存';
                            saveBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('发生了一个网络或脚本错误，请稍后再试。');
                        saveBtn.textContent = '保存';
                        saveBtn.disabled = false;
                    });
                });
            });
        }
    });
    </script>
</body>
</html>```