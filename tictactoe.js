let ws;
let playerSymbol;
let currentTurn;
let gameActive = false;
let hasVotedRematch = false;
let rematchTimerInterval;
let isConnected = false;
let turnTimerInterval;
let timeLeft;
const TURN_TIMEOUT = 5;
let isTimeout = false;

document.getElementById('startBtn').addEventListener('click', function() {
    document.getElementById('startScreen').style.display = 'none';
    document.getElementById('gameBoard').style.display = 'block';
    init();
});

function init() {
    if (!isConnected) {
        ws = new WebSocket('ws://127.0.0.1:8080');
        
        ws.onopen = () => {
            console.log('Connected to server');
            isConnected = true;
            resetBoard();
            document.getElementById('playerInfo').textContent = 'Connecting to server...';
            document.getElementById('status').textContent = 'Waiting for opponent...';
            setupBoard();
            setupChat(); 
        };

        ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            handleMessage(data);
        };

        ws.onclose = (event) => {
            if (!event.wasClean) {
                document.getElementById('status').textContent = 'Connection lost. Please refresh the page and try again.';
            }
            isConnected = false;
            gameActive = false;
            resetBoard();
        };
    }
}

function setupChat() {
    document.getElementById('chatContainer').style.display = 'block';
    
    const chatInput = document.getElementById('chatInput');
    const chatSend = document.getElementById('chatSend');
    
    function sendMessage() {
        const message = chatInput.value.trim();
        if (message) {
            ws.send(JSON.stringify({
                type: 'chat',
                message: message
            }));
            chatInput.value = '';
        }
    }

    chatInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            sendMessage();
        }
    });

    chatSend.addEventListener('click', sendMessage);
}

function setupBoard() {
    const cells = document.getElementsByClassName('cell');
    for (let cell of cells) {
        cell.removeEventListener('click', cellClickHandler);
        cell.addEventListener('click', cellClickHandler);
        cell.classList.remove('disabled');
    }

    const rematchBtn = document.getElementById('rematchBtn');
    rematchBtn.removeEventListener('click', rematchBtnHandler);
    rematchBtn.addEventListener('click', rematchBtnHandler);
}

function rematchBtnHandler() {
    if (!hasVotedRematch) {
        ws.send(JSON.stringify({ type: 'rematchVote' }));
        hasVotedRematch = true;
        document.getElementById('rematchBtn').disabled = true;
    }
}

function handleMessage(data) {
    if (data.type === 'chat') {
        const chatMessages = document.getElementById('chatMessages');
        const messageDiv = document.createElement('div');
        messageDiv.className = 'chat-message';
        messageDiv.innerHTML = `
            <span class="player">${data.player}:</span>
            <span class="message">${data.message}</span>
            <span class="time">${data.timestamp}</span>
        `;
        chatMessages.appendChild(messageDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        return;
    }

    if (data.type === 'gameOver') {
        updateGameHistory(data.gameHistory, playerSymbol);
    }

    switch(data.type) {
        case 'connect':
            playerSymbol = data.symbol;
            document.getElementById('playerInfo').textContent = data.message;
            resetBoard();
            setupBoard();
            break;

        case 'start':
            gameActive = true;
            currentTurn = data.turn;
            updateTurnTimer(TURN_TIMEOUT);
            const startCells = document.getElementsByClassName('cell');
            for (let cell of startCells) {
                cell.textContent = '';
            }
            if (data.isFirstGame) {
                document.getElementById('playerInfo').textContent = `You are player ${playerSymbol}`;
            }
            break;

        case 'move':
            const cell = document.querySelector(`[data-index="${data.position}"]`);
            if (cell) {
                cell.textContent = data.symbol;
                if (gameActive && data.symbol !== playerSymbol) {
                    updateTurnTimer(TURN_TIMEOUT);
                }
            }
            break;

        case 'turn':
            currentTurn = data.turn;
            updateTurnTimer(data.timeout || TURN_TIMEOUT);
            break;

        case 'gameOver':
            gameActive = false;
            clearInterval(turnTimerInterval);
            isTimeout = false;
            
            const cells = document.getElementsByClassName('cell');
            for (let cell of cells) {
                cell.classList.add('disabled');
            }
            
            if (data.reason === 'timeout') {
                const isCurrentPlayerTimeout = data.timeoutPlayer === playerSymbol;
                const statusMessage = isCurrentPlayerTimeout ?
                    "Game Over - You lost due to timeout!" :
                    "Game Over - You won! Opponent's time ran out!" ;
                document.getElementById('status').textContent = statusMessage;
            } else if (data.winner === 'draw') {
                document.getElementById('status').textContent = "Game Over - It's a Draw!";
            } else {
                document.getElementById('status').textContent = `Game Over - Player ${data.winner} Wins!`;
            }
            
            document.getElementById('rematchBtn').style.display = 'block';
            document.getElementById('rematchBtn').disabled = false;
            hasVotedRematch = false;
            document.getElementById('rematchStatus').textContent = 'Waiting for players to vote for rematch...';
            break;

        case 'rematchVoteUpdate':
            updateRematchStatus(data.votes);
            break;

        case 'restart':
            gameActive = true;
            currentTurn = data.turn;
            isTimeout = false;
            
            const restartCells = document.getElementsByClassName('cell');
            for (let cell of restartCells) {
                cell.textContent = '';
                cell.classList.remove('disabled');
            }
            
            updateStatus();
            document.getElementById('rematchBtn').style.display = 'none';
            document.getElementById('rematchStatus').textContent = '';
            
            updateTurnTimer(TURN_TIMEOUT);
            
            setupBoard();
            break;

        case 'error':
            document.getElementById('status').textContent = data.message;
            resetBoard();
            break;

        case 'playerDisconnected':
            clearInterval(turnTimerInterval);
            document.getElementById('status').textContent = data.message;
            gameActive = false;
            if (data.reset) {
                resetBoard();
                updateGameHistory(data.gameHistory, playerSymbol);
                document.getElementById('playerInfo').textContent = 'Waiting for opponent...';
            }
            break;

        case 'rematchTimerStart':
            startRematchTimer(data.timeout);
            break;

        case 'waitingForNewPlayer':
            document.getElementById('status').textContent = data.message;
            resetBoard();
            gameActive = false;
            document.getElementById('playerInfo').textContent = 'Waiting for opponent...';
            break;
                
        case 'stopRematchTimer':
            clearRematchTimer();
            document.getElementById('rematchStatus').textContent = '';
            break;

        case 'timeoutDisconnect':
            document.getElementById('status').textContent = data.message;
            resetBoard();
            break;
    }
}

function updateGameHistory(history, playerSymbol) {
    const historyContainer = document.getElementById('historyItems');
    historyContainer.innerHTML = '';
            
    history.forEach((game) => {
        const span = document.createElement('span');
        span.className = 'history-item';
                
        if (game.result === 'draw') {
            span.textContent = 'D';
            span.classList.add('history-draw');
        } else if (game.result === playerSymbol) {
            span.textContent = 'W';
            span.classList.add('history-win');
        } else {
            span.textContent = 'L';
            span.classList.add('history-lose');
        }
                
        historyContainer.appendChild(span);
    });
}

function updateTurnTimer(TURN_TIMEOUT) {
    clearInterval(turnTimerInterval);
    timeLeft = TURN_TIMEOUT;

    const statusElement = document.getElementById('status');
    const currentStatus = currentTurn === playerSymbol ? "Your turn" : "Opponent's turn";
            
    statusElement.textContent = `${currentStatus} (${timeLeft}s)`;

    turnTimerInterval = setInterval(() => {
        timeLeft--;
        if (timeLeft >= 0) {
            statusElement.textContent = `${currentStatus} (${timeLeft}s)`;
        }
                
        if (timeLeft <= 0) {
            isTimeout = true;
            clearInterval(turnTimerInterval);
                    
            ws.send(JSON.stringify({
                type: 'turnTimeout',
                player: playerSymbol
            }));
        }
    }, 1000);
}

function cellClickHandler(event) {
    if (!gameActive || currentTurn !== playerSymbol || isTimeout) {
return;}
            
    const cell = event.target;
    if (cell.textContent !== '') {
        return;
    }

    clearInterval(turnTimerInterval);
            
    ws.send(JSON.stringify({
        type: 'move',
        position: parseInt(cell.dataset.index)
    }));
}

function updateRematchStatus(votes) {
    const rematchStatus = document.getElementById('rematchStatus');
    const votedPlayers = Object.entries(votes)
        .filter(([_, voted]) => voted)
        .map(([symbol, _]) => symbol);
            
    if (votedPlayers.length === 0) {
        rematchStatus.textContent = 'Waiting for players to vote for rematch...';
    } else if (votedPlayers.length === 1) {
        rematchStatus.textContent = `Player ${votedPlayers[0]} voted for rematch. Waiting for other player...`;
    }
}

function startRematchTimer(timeout) {
    clearRematchTimer();
    let timeLeft = timeout;
    rematchTimerInterval = setInterval(() => {
        timeLeft--;
        document.getElementById('rematchStatus').textContent = 
            `Time remaining to vote for rematch: ${timeLeft} seconds`;
                
        if (timeLeft <= 0) {
            clearRematchTimer();
            if (!hasVotedRematch) {
                document.getElementById('rematchStatus').textContent = 
                    'Rematch time expired';
                document.getElementById('rematchBtn').style.display = 'none';
            }
        }
    }, 1000);
}

function clearRematchTimer() {
    if (rematchTimerInterval) {
        clearInterval(rematchTimerInterval);
        rematchTimerInterval = null;
    }
}

function updateStatus() {
    const status = document.getElementById('status');
    if (currentTurn === playerSymbol) {
        status.textContent = "Your turn";
    } else {
        status.textContent = "Opponent's turn";
    }
}

function resetBoard() {
    clearInterval(turnTimerInterval);
    isTimeout = false;
            
    const cells = document.getElementsByClassName('cell');
    for (let cell of cells) {
        cell.textContent = '';
        cell.classList.remove('disabled');
    }
            
    gameActive = false;
    currentTurn = 'X';
    hasVotedRematch = false;
    document.getElementById('rematchBtn').style.display = 'none';
    document.getElementById('rematchBtn').disabled = false;
    document.getElementById('rematchStatus').textContent = '';
    document.getElementById('status').textContent = 'Waiting for opponent...';
    clearRematchTimer();
    setupBoard();
}