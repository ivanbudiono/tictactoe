<?php
require 'vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory;

class TicTacToeServer implements \Ratchet\MessageComponentInterface {
    private $clients;
    private $players;
    private $gameState;
    private $currentTurn;
    private $rematchVotes;
    private $playerSlots;
    private $rematchTimer;
    private $rematchTimeout = 30;
    private $waitingForRematch = null;
    private $lastRematchVoter = null;
    private $chatHistory = [];
    private $gameHistory = [];
    private $turnTimeout = 5;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->players = [];
        $this->playerSlots = ['X' => null, 'O' => null];
        $this->resetGame();
        $this->rematchTimer = null;
        $this->waitingForRematch = null;
        $this->lastRematchVoter = null;
    }

    private function resetGame() {
        $this->gameState = array_fill(0, 9, '');
        $this->currentTurn = 'X';
        $this->rematchVotes = ['X' => false, 'O' => false];
        $this->chatHistory = [];
        
        foreach ($this->players as $player) {
            $player['conn']->send(json_encode([
                'type' => 'turn',
                'turn' => $this->currentTurn,
                'timeout' => $this->turnTimeout
            ]));
        }
    }

    public function onOpen(\Ratchet\ConnectionInterface $conn) {
        if ($this->playerSlots['X'] !== null && $this->playerSlots['O'] !== null) {
            $conn->send(json_encode([
                'type' => 'error'
            ]));
            $conn->close();
            return;
        }
    
        $this->clients->attach($conn);
        
        $assignedSymbol = null;
        
        if ($this->waitingForRematch !== null) {
            $waitingPlayer = $this->players[$this->waitingForRematch];
            $assignedSymbol = ($waitingPlayer['symbol'] === 'X') ? 'O' : 'X';
            $this->playerSlots[$assignedSymbol] = $conn->resourceId;

            $this->waitingForRematch = null;
        } else {
            if ($this->playerSlots['X'] === null) {
                $assignedSymbol = 'X';
                $this->playerSlots['X'] = $conn->resourceId;
            } elseif ($this->playerSlots['O'] === null) {
                $assignedSymbol = 'O';
                $this->playerSlots['O'] = $conn->resourceId;
            }
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
    
            if (count($this->players) === 2) {
                $this->rematchVotes = ['X' => false, 'O' => false];
                $this->rematchTimer = null;
                $this->lastRematchVoter = null;
                $this->waitingForRematch = null;
    
                $this->resetGame();
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'start',
                        'turn' => $this->currentTurn,
                        'isFirstGame' => true
                    ]));
                }
            }
        } else {
            $conn->send(json_encode([
                'type' => 'error'
            ]));
            $conn->close();
        }
    }

    public function onMessage(\Ratchet\ConnectionInterface $from, $msg) {
        $data = json_decode($msg);
        
        if ($data->type === 'chat') {
            if (isset($this->players[$from->resourceId])) {
                $playerSymbol = $this->players[$from->resourceId]['symbol'];
                date_default_timezone_set('Asia/Jakarta');
                $chatMessage = [
                    'type' => 'chat',
                    'player' => $playerSymbol,
                    'message' => $data->message,
                    'timestamp' => date('H:i:s')
                ];
                
                $this->chatHistory[] = $chatMessage;
                
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode($chatMessage));
                }
            }
            return;
        }

        if ($data->type === 'turnTimeout') {
            if (isset($this->players[$from->resourceId])) {
                $winner = ($this->currentTurn === 'X') ? 'O' : 'X';
                
                $this->addGameToHistory($winner, 'timeout');
                
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'gameOver',
                        'winner' => $winner,
                        'reason' => 'timeout',
                        'timeoutPlayer' => $this->currentTurn,
                        'gameHistory' => $this->gameHistory
                    ]));
                }
                
                $this->gameState = array_fill(0, 9, '');
                return;
            }
        }

        if ($data->type === 'move') {
            if (isset($this->players[$from->resourceId]) && 
                $this->players[$from->resourceId]['symbol'] === $this->currentTurn) {
                
                if ($this->gameState[$data->position] === '') {
                    $this->gameState[$data->position] = $this->currentTurn;
                    
                    foreach ($this->players as $player) {
                        $player['conn']->send(json_encode([
                            'type' => 'move',
                            'position' => $data->position,
                            'symbol' => $this->currentTurn
                        ]));
                    }

                    if ($this->checkWin()) {
                        $this->addGameToHistory($this->currentTurn);
                        
                        foreach ($this->players as $player) {
                            $player['conn']->send(json_encode([
                                'type' => 'gameOver',
                                'winner' => $this->currentTurn,
                                'gameHistory' => $this->gameHistory
                            ]));
                        }
                    } elseif ($this->checkDraw()) {
                        $this->addGameToHistory('draw');
                        
                        foreach ($this->players as $player) {
                            $player['conn']->send(json_encode([
                                'type' => 'gameOver',
                                'winner' => 'draw',
                                'gameHistory' => $this->gameHistory
                            ]));
                        }
                    } else {
                        $this->currentTurn = ($this->currentTurn === 'X') ? 'O' : 'X';
                        foreach ($this->players as $player) {
                            $player['conn']->send(json_encode([
                                'type' => 'turn',
                                'turn' => $this->currentTurn,
                                'timeout' => $this->turnTimeout
                            ]));
                        }
                    }
                }
            }
        } elseif ($data->type === 'rematchVote') {
            if ($this->lastRematchVoter !== null && !isset($this->players[$this->lastRematchVoter])) {
                $this->lastRematchVoter = null;
            }
            
            $playerSymbol = $this->players[$from->resourceId]['symbol'];
            $this->rematchVotes[$playerSymbol] = true;
            $this->lastRematchVoter = $from->resourceId;
            
            if ($this->rematchTimer === null) {
                $this->rematchTimer = time();
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'rematchTimerStart',
                        'timeout' => $this->rematchTimeout
                    ]));
                }
            }
            
            $timeElapsed = time() - $this->rematchTimer;
            if ($timeElapsed > $this->rematchTimeout) {
                $this->handleRematchTimeout($playerSymbol);
                return;
            }
        
            foreach ($this->players as $player) {
                $player['conn']->send(json_encode([
                    'type' => 'rematchVoteUpdate',
                    'votes' => $this->rematchVotes,
                    'timeLeft' => $this->rematchTimeout - $timeElapsed
                ]));
            }
    
            if ($this->rematchVotes['X'] && $this->rematchVotes['O']) {
                $this->rematchTimer = null;
                $this->lastRematchVoter = null;
                $this->resetGame();
                
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'stopRematchTimer'
                    ]));
                }
                
                foreach ($this->players as $player) {
                    $player['conn']->send(json_encode([
                        'type' => 'restart',
                        'turn' => $this->currentTurn
                    ]));
                }
            }
        }
    }

    public function onClose(\Ratchet\ConnectionInterface $conn) {
        if (isset($this->players[$conn->resourceId])) {
            $symbol = $this->players[$conn->resourceId]['symbol'];
            $this->playerSlots[$symbol] = null;
            unset($this->players[$conn->resourceId]);

            $this->chatHistory = [];
            $this->gameHistory = [];
            $this->resetGame();
            
            foreach ($this->players as $player) {
                $player['conn']->send(json_encode([
                    'type' => 'playerDisconnected',
                    'reset' => true
                ]));
            }
        }
        $this->clients->detach($conn);
    }

    public function onError(\Ratchet\ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }

    private function addGameToHistory($winner, $reason = 'normal') {
        date_default_timezone_set('Asia/Jakarta');
        $this->gameHistory[] = [
            'result' => $winner,
            'reason' => $reason,
            'timestamp' => date('H:i:s')
        ];
    }    

    private function checkWin() {
        $winPatterns = [
            [0, 1, 2], [3, 4, 5], [6, 7, 8],
            [0, 3, 6], [1, 4, 7], [2, 5, 8],
            [0, 4, 8], [2, 4, 6]
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

    private function handleRematchTimeout($votedSymbol) {    
        foreach ($this->players as $resourceId => $player) {
            if ($player['symbol'] === $votedSymbol) {
                $this->waitingForRematch = $resourceId;
            
                $player['conn']->send(json_encode([
                    'type' => 'waitingForNewPlayer',
                    'message' => 'Opponent disconnected. Waiting for new opponent...'
                ]));
            } else {
                $player['conn']->send(json_encode([
                    'type' => 'timeoutDisconnect',
                    'message' => 'You have been disconnected due to rematch timeout'
                ]));
                $player['conn']->close();
                unset($this->players[$resourceId]);
                $this->playerSlots[$player['symbol']] = null;
            }
        }

        $this->rematchTimer = null;
        $this->rematchVotes = ['X' => false, 'O' => false];
        $this->lastRematchVoter = null;
    }
}

$loop = Factory::create();
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