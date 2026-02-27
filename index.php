<?php
require_once __DIR__ . '/includes/config.php';

$db = Database::getInstance();

// Get active rooms
$stmt = $db->query(
    "SELECT r.*, a.username as created_by_name,
            (SELECT COUNT(*) FROM room_participants WHERE room_id = r.id AND left_at IS NULL) as participant_count
     FROM rooms r
     JOIN admins a ON r.created_by = a.id
     WHERE r.is_active = 1
     ORDER BY participant_count DESC, r.created_at DESC"
);
$rooms = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voice Chat - Clubhouse Style</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <div id="app">
        <!-- Header -->
        <header class="app-header">
            <div class="header-left">
                <h1>🎙️ Voice Chat</h1>
            </div>
            <div class="header-right">
                <div id="userInfo" class="user-info" style="display: none;">
                    <span id="userDisplayName"></span>
                    <button onclick="logout()" class="btn btn-outline btn-sm">Logout</button>
                </div>
                <button id="loginBtn" onclick="showLoginModal()" class="btn btn-primary">Login</button>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Login Modal -->
            <div id="loginModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2>Welcome Back</h2>
                        <button class="close-btn" onclick="closeModal('loginModal')">&times;</button>
                    </div>
                    <form id="loginForm" class="modal-body">
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" required autocomplete="username">
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required autocomplete="current-password">
                        </div>
                        <div id="loginError" class="error-message"></div>
                        <button type="submit" class="btn btn-primary btn-block">Login</button>
                    </form>
                </div>
            </div>

            <!-- Room View (hidden by default) -->
            <div id="roomView" class="room-view" style="display: none;">
                <div class="room-header">
                    <button onclick="leaveRoom()" class="btn btn-outline">
                        ← Leave Room
                    </button>
                    <div class="room-info">
                        <h2 id="roomName"></h2>
                        <span id="roomParticipants"></span>
                    </div>
                    <div class="room-actions">
                        <button id="handRaiseBtn" onclick="toggleHand()" class="btn btn-secondary" style="display: none;">
                            ✋ Raise Hand
                        </button>
                    </div>
                </div>

                <div class="room-content">
                    <!-- Speakers Section -->
                    <div class="section">
                        <h3>🎤 Speakers</h3>
                        <div id="speakersList" class="participants-grid">
                            <!-- Speakers will be populated here -->
                        </div>
                    </div>

                    <!-- Listeners Section -->
                    <div class="section">
                        <h3>👂 Listeners</h3>
                        <div id="listenersList" class="participants-grid">
                            <!-- Listeners will be populated here -->
                        </div>
                    </div>
                </div>

                <!-- Audio Controls -->
                <div class="audio-controls">
                    <button id="muteBtn" onclick="toggleMute()" class="btn btn-primary btn-lg">
                        🎤 Mute
                    </button>
                    <span id="audioStatus">Muted</span>
                </div>
            </div>

            <!-- Lobby View (default) -->
            <div id="lobbyView" class="lobby-view">
                <div class="lobby-header">
                    <h2>Active Rooms</h2>
                    <p>Join a room to start chatting</p>
                </div>

                <div id="roomsList" class="rooms-grid">
                    <?php if (empty($rooms)): ?>
                        <div class="empty-state">
                            <h3>No active rooms</h3>
                            <p>Check back later or ask an admin to create a room</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($rooms as $room): ?>
                            <div class="room-card" onclick="joinRoom(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['name']); ?>')">
                                <div class="room-card-header">
                                    <h3><?php echo htmlspecialchars($room['name']); ?></h3>
                                    <span class="room-status active">Live</span>
                                </div>
                                <?php if ($room['description']): ?>
                                    <p class="room-description"><?php echo htmlspecialchars($room['description']); ?></p>
                                <?php endif; ?>
                                <div class="room-card-footer">
                                    <div class="room-avatars">
                                        <!-- Placeholder avatars -->
                                        <div class="avatar-stack">
                                            <span class="avatar">👤</span>
                                        </div>
                                    </div>
                                    <div class="room-meta">
                                        <span><?php echo $room['participant_count']; ?> listening</span>
                                        <span>•</span>
                                        <span>Max <?php echo $room['max_participants']; ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="/assets/js/webrtc.js"></script>
    <script>
        // State
        let currentUser = null;
        let currentRoom = null;
        let isMuted = true;
        let isSpeaker = false;
        let handRaised = false;
        let ws = null;
        let peerConnections = {};
        let localStream = null;

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            checkSession();
            connectWebSocket();
        });

        // Check existing session
        async function checkSession() {
            try {
                const response = await fetch('/api/auth.php?action=check');
                const data = await response.json();
                
                if (data.authenticated) {
                    currentUser = data.member;
                    updateUserUI();
                }
            } catch (error) {
                console.error('Session check failed:', error);
            }
        }

        // Update UI for logged in user
        function updateUserUI() {
            if (currentUser) {
                document.getElementById('loginBtn').style.display = 'none';
                document.getElementById('userInfo').style.display = 'flex';
                document.getElementById('userDisplayName').textContent = 
                    currentUser.display_name || currentUser.username;
            } else {
                document.getElementById('loginBtn').style.display = 'block';
                document.getElementById('userInfo').style.display = 'none';
            }
        }

        // Login
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            const errorDiv = document.getElementById('loginError');
            
            errorDiv.textContent = '';
            
            try {
                const response = await fetch('/api/auth.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    currentUser = data.member;
                    updateUserUI();
                    closeModal('loginModal');
                    
                    // Store token
                    localStorage.setItem('token', data.token);
                } else {
                    errorDiv.textContent = data.error || 'Login failed';
                }
            } catch (error) {
                errorDiv.textContent = 'Connection error';
            }
        });

        // Logout
        async function logout() {
            try {
                await fetch('/api/auth.php?action=logout');
                currentUser = null;
                localStorage.removeItem('token');
                updateUserUI();
                
                if (currentRoom) {
                    leaveRoom();
                }
            } catch (error) {
                console.error('Logout failed:', error);
            }
        }

        // Show login modal
        function showLoginModal() {
            document.getElementById('loginForm').reset();
            document.getElementById('loginError').textContent = '';
            document.getElementById('loginModal').style.display = 'flex';
        }

        // Close modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Join room
        async function joinRoom(roomId, roomName) {
            if (!currentUser) {
                showLoginModal();
                return;
            }
            
            try {
                const response = await fetch('/api/rooms.php?action=join', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    },
                    body: JSON.stringify({ room_id: roomId })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    currentRoom = { id: roomId, name: roomName };
                    isSpeaker = data.is_speaker;
                    
                    // Switch to room view
                    document.getElementById('lobbyView').style.display = 'none';
                    document.getElementById('roomView').style.display = 'block';
                    document.getElementById('roomName').textContent = roomName;
                    
                    // Show/hide hand raise button for non-speakers
                    if (!isSpeaker) {
                        document.getElementById('handRaiseBtn').style.display = 'block';
                    }
                    
                    // Initialize audio
                    await initAudio();
                    
                    // Notify WebSocket
                    if (ws && ws.readyState === WebSocket.OPEN) {
                        ws.send(JSON.stringify({
                            type: 'join',
                            roomId: roomId,
                            userId: currentUser.id,
                            username: currentUser.display_name || currentUser.username,
                            isSpeaker: isSpeaker
                        }));
                    }
                    
                    // Start polling for participants
                    pollParticipants();
                } else {
                    alert(data.error || 'Failed to join room');
                }
            } catch (error) {
                console.error('Join room failed:', error);
                alert('Failed to join room');
            }
        }

        // Leave room
        async function leaveRoom() {
            if (!currentRoom) return;
            
            try {
                await fetch('/api/rooms.php?action=leave', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    },
                    body: JSON.stringify({ room_id: currentRoom.id })
                });
            } catch (error) {
                console.error('Leave room failed:', error);
            }
            
            // Stop audio
            if (localStream) {
                localStream.getTracks().forEach(track => track.stop());
                localStream = null;
            }
            
            // Close peer connections
            Object.values(peerConnections).forEach(pc => pc.close());
            peerConnections = {};
            
            // Notify WebSocket
            if (ws && ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({
                    type: 'leave',
                    roomId: currentRoom.id,
                    userId: currentUser.id
                }));
            }
            
            currentRoom = null;
            
            // Switch to lobby view
            document.getElementById('roomView').style.display = 'none';
            document.getElementById('lobbyView').style.display = 'block';
        }

        // Initialize audio
        async function initAudio() {
            try {
                localStream = await navigator.mediaDevices.getUserMedia({ 
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true
                    }, 
                    video: false 
                });
                
                // Mute by default
                localStream.getAudioTracks().forEach(track => {
                    track.enabled = false;
                });
                
                isMuted = true;
                updateMuteButton();
            } catch (error) {
                console.error('Failed to get audio:', error);
                alert('Could not access microphone. Please allow microphone access.');
            }
        }

        // Toggle mute
        function toggleMute() {
            if (!localStream) return;
            
            isMuted = !isMuted;
            
            localStream.getAudioTracks().forEach(track => {
                track.enabled = !isMuted;
            });
            
            updateMuteButton();
            
            // Update status via API
            fetch('/api/rooms.php?action=update-status', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + localStorage.getItem('token')
                },
                body: JSON.stringify({ 
                    room_id: currentRoom.id, 
                    is_muted: isMuted ? 1 : 0 
                })
            });
            
            // Notify WebSocket
            if (ws && ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({
                    type: 'mute',
                    roomId: currentRoom.id,
                    userId: currentUser.id,
                    isMuted: isMuted
                }));
            }
        }

        // Update mute button
        function updateMuteButton() {
            const btn = document.getElementById('muteBtn');
            const status = document.getElementById('audioStatus');
            
            if (isSpeaker) {
                if (isMuted) {
                    btn.textContent = '🔇 Unmute';
                    btn.className = 'btn btn-secondary btn-lg';
                    status.textContent = 'Muted';
                } else {
                    btn.textContent = '🎤 Mute';
                    btn.className = 'btn btn-primary btn-lg';
                    status.textContent = 'Speaking';
                }
            } else {
                btn.textContent = '🔇 Listen Only';
                btn.className = 'btn btn-outline btn-lg';
                btn.disabled = true;
                status.textContent = 'Listener';
            }
        }

        // Toggle hand raise
        function toggleHand() {
            handRaised = !handRaised;
            
            const btn = document.getElementById('handRaiseBtn');
            btn.textContent = handRaised ? '✋ Hand Lowered' : '✋ Raise Hand';
            btn.className = handRaised ? 'btn btn-warning' : 'btn btn-secondary';
            
            fetch('/api/rooms.php?action=raise-hand', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + localStorage.getItem('token')
                },
                body: JSON.stringify({ 
                    room_id: currentRoom.id, 
                    raised: handRaised ? 1 : 0 
                })
            });
            
            // Notify WebSocket
            if (ws && ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({
                    type: 'hand',
                    roomId: currentRoom.id,
                    userId: currentUser.id,
                    raised: handRaised
                }));
            }
        }

        // Poll for participants
        let pollInterval;
        function pollParticipants() {
            if (pollInterval) clearInterval(pollInterval);
            
            fetchParticipants();
            pollInterval = setInterval(fetchParticipants, 3000);
        }

        async function fetchParticipants() {
            if (!currentRoom) return;
            
            try {
                const response = await fetch(`/api/rooms.php?action=participants&room_id=${currentRoom.id}`);
                const data = await response.json();
                
                renderParticipants(data.speakers, data.listeners);
            } catch (error) {
                console.error('Failed to fetch participants:', error);
            }
        }

        // Render participants
        function renderParticipants(speakers, listeners) {
            const speakersDiv = document.getElementById('speakersList');
            const listenersDiv = document.getElementById('listenersList');
            
            speakersDiv.innerHTML = speakers.map(p => createParticipantCard(p, true)).join('') || 
                '<p class="empty-text">No speakers yet</p>';
            
            listenersDiv.innerHTML = listeners.map(p => createParticipantCard(p, false)).join('') || 
                '<p class="empty-text">No listeners</p>';
            
            document.getElementById('roomParticipants').textContent = 
                `${speakers.length + listeners.length} participants`;
        }

        function createParticipantCard(participant, isSpeaker) {
            const initials = (participant.display_name || participant.username).charAt(0).toUpperCase();
            const isCurrentUser = currentUser && participant.member_id === currentUser.id;
            
            return `
                <div class="participant-card ${isCurrentUser ? 'current-user' : ''}">
                    <div class="participant-avatar">
                        ${initials}
                        ${isSpeaker && !participant.is_muted ? '<span class="speaking-indicator"></span>' : ''}
                    </div>
                    <div class="participant-info">
                        <span class="participant-name">
                            ${participant.display_name || participant.username}
                            ${isCurrentUser ? ' (You)' : ''}
                        </span>
                        ${participant.hand_raised ? '<span class="hand-badge">✋ Hand Raised</span>' : ''}
                        ${!participant.is_muted && isSpeaker ? '<span class="speaking-badge">Speaking</span>' : ''}
                    </div>
                </div>
            `;
        }

        // Connect WebSocket
        function connectWebSocket() {
            const wsProtocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
            ws = new WebSocket(`${wsProtocol}//${window.location.hostname}:4001`);
            
            ws.onopen = () => {
                console.log('WebSocket connected');
                
                // Rejoin room if we were in one
                if (currentRoom && currentUser) {
                    ws.send(JSON.stringify({
                        type: 'join',
                        roomId: currentRoom.id,
                        userId: currentUser.id,
                        username: currentUser.display_name || currentUser.username,
                        isSpeaker: isSpeaker
                    }));
                }
            };
            
            ws.onmessage = (event) => {
                const data = JSON.parse(event.data);
                handleWebSocketMessage(data);
            };
            
            ws.onclose = () => {
                console.log('WebSocket disconnected, reconnecting...');
                setTimeout(connectWebSocket, 3000);
            };
            
            ws.onerror = (error) => {
                console.error('WebSocket error:', error);
            };
        }

        // Handle WebSocket messages
        function handleWebSocketMessage(data) {
            switch (data.type) {
                case 'user-joined':
                case 'user-left':
                case 'user-muted':
                case 'user-hand':
                    fetchParticipants();
                    break;
                    
                case 'room-ended':
                    alert('This room has been ended by the admin.');
                    leaveRoom();
                    break;
                    
                case 'made-speaker':
                    isSpeaker = true;
                    document.getElementById('handRaiseBtn').style.display = 'none';
                    updateMuteButton();
                    fetchParticipants();
                    break;
                    
                case 'kicked':
                    alert('You have been removed from the room.');
                    leaveRoom();
                    break;
                    
                // WebRTC signaling
                case 'offer':
                case 'answer':
                case 'ice-candidate':
                    handleWebRTCSignal(data);
                    break;
            }
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
