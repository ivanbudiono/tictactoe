<?php
// server.php
require 'vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class TicTacToeServer implements \Ratchet\MessageComponentInterface {
    private $clients;
    private $players;
    private $gameState;
    private $currentTurn;
    private $playerSlots;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->players = [];
        $this->playerSlots = ['X' => null, 'O' => null];
        $this->resetGame();
    }

    private function resetGame() {
        $this->gameState = array_fill(0, 9, '');
        $this->currentTurn = 'X';

        $this->broadcast([
            'type' => 'reset',
            'message' => 'An opponent has left the game. The game will be reset',
            'gameState' => $this->gameState
        ]);
    }

    public function onOpen(\Ratchet\ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $assignedSymbol = null;

        // Assign player symbol
        if ($this->playerSlots['X'] === null) {
            $this->playerSlots['X'] = $conn->resourceId;
            $assignedSymbol = 'X';
        } elseif ($this->playerSlots['O'] === null) {
            $this->playerSlots['O'] = $conn->resourceId;
            $assignedSymbol = 'O';
        }

        if ($assignedSymbol !== null) {
            $this->players[$conn->resourceId] = [
                'conn' => $conn,
                'symbol' => $assignedSymbol
            ];

            $conn->send(json_encode([
                'type' => 'connect',
                'symbol' => $assignedSymbol,
                'message' => "You are player $assignedSymbol"
            ]));

            if (count(array_filter($this->playerSlots)) === 2) {
                $this->broadcast([
                    'type' => 'start',
                    'turn' => 'X',
                    'message' => 'Game started!'
                ]);
            } else {
                $conn->send(json_encode([
                    'type' => 'waiting',
                    'message' => 'Waiting for an opponent...'
                ]));
            }
        } else {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Game room is full'
            ]));
            $conn->close();
        }
    }

    public function onMessage(\Ratchet\ConnectionInterface $from, $msg) {
        $data = json_decode($msg);
    
        if ($data->type === 'move') {
            $player = $this->players[$from->resourceId] ?? null;
            if ($player && $player['symbol'] === $this->currentTurn && $this->gameState[$data->position] === '') {
                $this->gameState[$data->position] = $this->currentTurn;
    
                $this->broadcast([
                    'type' => 'move',
                    'position' => $data->position,
                    'symbol' => $this->currentTurn
                ]);
    
                if ($this->checkWin()) {
                    $this->broadcast([
                        'type' => 'gameOver',
                        'winner' => $this->currentTurn,
                    ]);
                } elseif ($this->checkDraw()) {
                    $this->broadcast([
                        'type' => 'gameOver',
                        'winner' => 'draw',
                    ]);
                } else {
                    $this->currentTurn = ($this->currentTurn === 'X') ? 'O' : 'X';
                    $this->broadcast([
                        'type' => 'turn',
                        'turn' => $this->currentTurn
                    ]);
                }
            }
        }
    }    

    public function onClose(\Ratchet\ConnectionInterface $conn) {
        if (isset($this->players[$conn->resourceId])) {
            $symbol = $this->players[$conn->resourceId]['symbol'];
            $this->playerSlots[$symbol] = null;
            unset($this->players[$conn->resourceId]);

            $this->broadcast([
                'type' => 'playerDisconnected',
                'message' => "Player $symbol has disconnected. Game will reset."
            ]);

            $this->resetGame();

            foreach ($this->clients as $client) {
                $client->send(json_encode([
                    'type' => 'waiting',
                    'message' => 'Waiting for new players...'
                ]));
            }
        }

        $this->clients->detach($conn);
    }

    public function onError(\Ratchet\ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }

    private function checkWin() {
        $winPatterns = [
            [0, 1, 2], [3, 4, 5], [6, 7, 8], // Rows
            [0, 3, 6], [1, 4, 7], [2, 5, 8], // Columns
            [0, 4, 8], [2, 4, 6] // Diagonals
        ];

        foreach ($winPatterns as $pattern) {
            if ($this->gameState[$pattern[0]] !== '' &&
                $this->gameState[$pattern[0]] === $this->gameState[$pattern[1]] &&
                $this->gameState[$pattern[1]] === $this->gameState[$pattern[2]]) {
                return true;
            }
        }
        return false;
    }

    private function checkDraw() {
        return !in_array('', $this->gameState);
    }

    private function broadcast($message) {
        foreach ($this->clients as $client) {
            $client->send(json_encode($message));
        }
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

echo "Server running at ws://127.0.0.1:8080\n";
$server->run();