<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

class TicTacToeServer implements MessageComponentInterface {
    protected $clients;
    private $board;
    private $turn;
    private $playerMap;
    private $gameOver;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->board = array_fill(0, 9, null);
        $this->turn = 0;
        $this->playerMap = [];
        $this->gameOver = false;
    }

    public function onOpen(ConnectionInterface $conn) {
        if ($this->clients->count() >= 2) {
            $conn->send(json_encode(['error' => 'Game is full!']));
            $conn->close();
            return;
        }

        $this->clients->attach($conn);
        $playerId = $this->clients->count() - 1;
        $this->playerMap[$conn->resourceId] = $playerId;

        $conn->send(json_encode(['message' => 'Welcome!', 'player' => $playerId]));
        echo "New connection: {$conn->resourceId} (Player {$playerId})\n";

        if ($this->clients->count() === 2) {
            $this->broadcast([
                'message' => 'Game Start', 
                'board' => $this->board, 
                'turn' => $this->turn,
                'status' => 'playing'
            ]);
        } else {
            $conn->send(json_encode([
                'message' => 'Waiting for an opponent...',
                'board' => null,
                'status' => 'waiting'
            ]));
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        if ($msg === 'restart') {
            $this->resetGame();
            return;
        }

        if ($this->gameOver) {
            $from->send(json_encode(['error' => 'Game is over! Please restart the game.']));
            return;
        }

        $player = $this->playerMap[$from->resourceId] ?? null;

        if ($player !== $this->turn) {
            $from->send(json_encode(['error' => 'Not your turn!']));
            return;
        }

        $index = (int)$msg;
        if ($this->board[$index] !== null || $index < 0 || $index > 8) {
            $from->send(json_encode(['error' => 'Invalid move!']));
            return;
        }

        $this->board[$index] = $player;
        $this->turn = 1 - $this->turn;

        $this->broadcast(['board' => $this->board, 'turn' => $this->turn]);

        if ($this->checkWin($player)) {
            $this->gameOver = true;
            $this->broadcast([
                'message' => "Player {$player} wins!",
                'gameOver' => true,
                'result' => "Player {$player} wins!",
                'showRematch' => true
            ]);
        } elseif (!in_array(null, $this->board)) {
            $this->gameOver = true;
            $this->broadcast([
                'message' => 'Draw!',
                'gameOver' => true,
                'result' => "DRAW!",
                'showRematch' => true
            ]);
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $playerId = $this->playerMap[$conn->resourceId] ?? null;
        $this->clients->detach($conn);
        unset($this->playerMap[$conn->resourceId]);

        if ($playerId !== null) {
            $this->broadcast(['message' => "Player {$playerId} has disconnected."]);
        }

        echo "Connection {$conn->resourceId} has disconnected\n";

        if ($this->clients->count() < 2) {
            $this->resetGame();
            $this->broadcast([
                'message' => 'A player has left. Game reset. Waiting for a new opponent...',
                'board' => null,
                'status' => 'waiting'
            ]);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

    private function broadcast($data) {
        foreach ($this->clients as $client) {
            $client->send(json_encode($data));
        }
    }

    private function resetGame() {
        $this->board = array_fill(0, 9, null);
        $this->turn = 0;
        $this->gameOver = false;
        $this->broadcast([
            'message' => 'Game reset. Waiting for another player...',
            'board' => $this->board,
            'turn' => $this->turn,
            'status' => 'waiting'
        ]);
    }

    private function checkWin($player) {
        $winningCombos = [
            [0, 1, 2], [3, 4, 5], [6, 7, 8], // Horizontal
            [0, 3, 6], [1, 4, 7], [2, 5, 8], // Vertical
            [0, 4, 8], [2, 4, 6] // Diagonal
        ];

        foreach ($winningCombos as $combo) {
            if ($this->board[$combo[0]] === $player && $this->board[$combo[1]] === $player && $this->board[$combo[2]] === $player) {
                return true;
            }
        }

        return false;
    }
}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new TicTacToeServer()
        )
    ),
    8080
);

$server->run();
