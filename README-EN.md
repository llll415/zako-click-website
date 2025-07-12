# 🎉 Zako Clicker Website

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF.svg)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/Database-MySQL-blue.svg)](https://www.mysql.com/)
* A Zako counter website generated purely by AI (~~and most of this README was too~~)*
* This page is also translated by Gemini
---

### ✨ Live Demo

**[Click here to see the Live Demo](https://zako.hoshino2.top/)**  

### 📸 Project Screenshot

![Project Screenshot](image.png) 

---

## 🚀 Key Features

*   **Core "Become a Zako" Functionality:**
    *   Users can click a button to register and become a "Zako" on the list.
    *   The total number of Zakos is displayed in real-time.

*   **User Identification Mechanism:**
    *   A **three-layer identification system** to prevent duplicate registrations:
        1.  **PHP Session:** Basic session-level identification.
        2.  **Client-side UUID:** A unique ID is stored in the browser's `LocalStorage` for persistent identification, even if the user closes the browser or clears their session.
        3.  **IP + User-Agent Fingerprint:** Acts as a final line of defense against bypass attempts like clearing browser cache.

*   **Interaction:**
    *   **Like System:** Users can "like" entries from other users. Each person can only like a specific entry once.
    *   **Nickname Editing:** Users can change their automatically generated nickname.
    *   **Comment Functionality:** Users can add or edit a comment for their own entry.
    *   All interactions (liking, editing) are handled via **AJAX** for a smooth, no-refresh experience.

*   **Information Display:**
    *   Automatically fetches and displays the user's **geolocation** and **Internet Service Provider (ISP)**.
    *   Automatically parses and displays the user's **Operating System** icon and name.
    *   Shows each user's registration time, IP address, nickname, and comment.

*   **Visuals & Audio:**
    *   Fun sound effects (`zako.mp3`) on button clicks and page interactions.
    *   Lively mouse trail, "杂鱼~♡" text pop-ups on clicks, and firework particle effects.
    *   **Responsive background images** designed for both PC and mobile devices.

*   **Technical Implementation:**
    *   Backend is written in native **PHP**.
    *   Uses **MySQL / MariaDB** for the database.
    *   Frontend is built with native **HTML/CSS/JavaScript**.

*   **Works Without a Public IP:**
    *   When using FRP, it can correctly obtain the user's public IP address **without needing to configure Proxy Protocol**.
---

## 🛠️ Technology Stack

*   **Backend:** PHP
*   **Database:** MySQL
*   **Frontend:** HTML, CSS, JavaScript (Vanilla JS)
*   **External Services:**
    *   [Font Awesome](https://fontawesome.com/) - For icons
    *   `ip9.com.cn` - For IP address geolocation lookup

---

## 📦 Installation and Deployment

Follow these steps to deploy this project on your server.

### 1. Clone the Repository
```bash
git clone https://github.com/your-username/zako-click-website.git
cd zako-click-website
```

### 2. Create the Database
Create a new database in your MySQL server. For example, name it `zako_db`.
* Use the following command in the MySQL Shell:
```bash
CREATE DATABASE <your_database_name> DEFAULT CHARSET=utf8;
```
### 3. Import Table Schema
*   ~~Connect to your database and execute the following SQL commands to create the required `user_clicks` and `user_likes` tables.~~
*   **You don't have to worry about this. Just create the database and connect to it. The tables will be created automatically if they don't exist.**
<details>
<summary>Table Structure</summary>

```sql
--
-- Table structure for `user_clicks`
--
CREATE TABLE `user_clicks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(255) NOT NULL,
  `client_uuid` varchar(36) DEFAULT NULL,
  `nickname` varchar(50) NOT NULL DEFAULT '匿名Zako',
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(255) NOT NULL,
  `operating_system` varchar(255) NOT NULL,
  `ip_location` varchar(255) NOT NULL,
  `isp` varchar(255) NOT NULL,
  `comment` text DEFAULT NULL,
  `likes_count` int(11) NOT NULL DEFAULT 0,
  `click_time` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_session` (`session_id`),
  UNIQUE KEY `unique_client_uuid` (`client_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for `user_likes`
--
CREATE TABLE `user_likes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `liker_uuid` varchar(36) NOT NULL,
  `liked_user_id` int(11) NOT NULL,
  `like_time` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_like` (`liker_uuid`,`liked_user_id`),
  KEY `liked_user_id` (`liked_user_id`),
  CONSTRAINT `user_likes_ibfk_1` FOREIGN KEY (`liked_user_id`) REFERENCES `user_clicks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
</details>

### 4. Configure Database Connection

<details>
<summary>Configure the database connection by editing the db_config.php file</summary>

* Edit the `db_config.php` file:
```php
<?php
// db_config.php

// Database host, usually 'localhost'.
$servername = "localhost";

// Your database username.
$username = "your_db_user";

// Your database password.
$password = "your_db_password";

// The name of the database you created.
$dbname = "zako_db";

// Create database connection.
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection.
if ($conn->connect_error) {
    // In a production environment, it's recommended to log errors instead of echoing them.
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8mb4 to support special characters like emoji.
$conn->set_charset("utf8mb4");
?>
```
</details>

---
### 5. Install Environment and Start PHP!
* Required PHP extensions: `mysqli`, `curl`, `session` (enabled by default), `json` (enabled by default), `mbstring`, `openssl` (enabled by default). Linux users can use:
```bash
sudo apt install php-{mysql,mbstring,curl} ## Debian/Ubuntu
```
* I might have missed some extensions. If you find any are missing, please open an issue.
* After installing the environment, start the server using `php -S x.x.x.x:xxxx`.
* Finally, open your browser and navigate to `x.x.x.x:xxxx` to access the site.
## 🙏 Acknowledgements

*   **Code Assistance:** Deepseek & Gemini
*   **IP Lookup API:** `ip9.com.cn`
*   **Icon Library:** [Font Awesome](https://fontawesome.com/)
*   **Font:** 萝莉体 第二版 (Loli Font v2)
---
## 🚨 Notice
*  **The default background image API is hosted on my home cloud via FRP. It is recommended to replace it.**
*  **The author himself doesn't know much about this stuff, otherwise it wouldn't be purely AI-generated.**
*  ~~**If you're going to flame me, please be gentle 😭😭**~~

## 📜 License

This project is licensed under the [MIT License](LICENSE).