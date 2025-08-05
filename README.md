# 🎮 Tic Tac Toe - Client Server (PHP)
This project is an implementation of the classic Tic Tac Toe game using a client-server architecture in PHP.
The game can be played by two players connected over a network (e.g., LAN or localhost), with one device running the server, and the other two devices acting as Player A and Player B, respectively.

## ✨ Key Features
- Play Tic Tac Toe between two devices over a network.
- One server can accept connections from two clients.
- Automatic turn system.
- Communication protocols include: finding opponents, playing turns, and determining wins or draws.

## ⚙️ How to Run
1. Clone the Repository
```bash
git clone https://github.com/ivanbudiono/tictactoe.git
cd tictactoe-client-server
```

2. Install Dependencies
```bash
composer install
```

3. Run the Server
```bash
php server.php
```
🧠 Note: By default, the server runs on ws://127.0.0.1:8080. Make sure the firewall allows the port if playing over LAN.

4. Connect Clients
Open the HTML file (e.g., client.html) in a browser on two different devices and make sure the WebSocket connection URL in the JavaScript matches the server IP and port.

Example in JS:
```bash
ws = new WebSocket('ws://127.0.0.1:8080');
```
Where 127.0.0.1 is the IP address of the device running the server.
