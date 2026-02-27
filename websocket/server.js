/**
 * WebSocket Signaling Server for Voice Chat
 * Run with: node websocket-server.js
 */

const WebSocket = require('ws');
const http = require('http');

// Configuration
const PORT = process.env.WS_PORT || 4001;
const HOST = process.env.WS_HOST || '0.0.0.0';

// Store active connections and rooms
const clients = new Map(); // userId -> { ws, userId, username, roomId, isSpeaker }
const rooms = new Map(); // roomId -> Set of userIds

// Create HTTP server
const server = http.createServer((req, res) => {
    res.writeHead(200, { 'Content-Type': 'text/plain' });
    res.end('Voice Chat WebSocket Server');
});

// Create WebSocket server
const wss = new WebSocket.Server({ server });

wss.on('connection', (ws) => {
    let userId = null;
    
    console.log('New connection established');
    
    ws.on('message', (message) => {
        try {
            const data = JSON.parse(message);
            
            switch (data.type) {
                case 'join':
                    handleJoin(ws, data);
                    userId = data.userId;
                    break;
                    
                case 'leave':
                    handleLeave(ws, data);
                    break;
                    
                case 'mute':
                    handleMute(ws, data);
                    break;
                    
                case 'hand':
                    handleHand(ws, data);
                    break;
                    
                // WebRTC signaling
                case 'offer':
                case 'answer':
                case 'ice-candidate':
                    handleSignaling(ws, data);
                    break;
                    
                default:
                    console.log('Unknown message type:', data.type);
            }
        } catch (error) {
            console.error('Error processing message:', error);
        }
    });
    
    ws.on('close', () => {
        if (userId) {
            handleDisconnect(userId);
        }
    });
    
    ws.on('error', (error) => {
        console.error('WebSocket error:', error);
    });
});

// Handle user joining a room
function handleJoin(ws, data) {
    const { roomId, userId, username, isSpeaker } = data;
    
    // Store client info
    clients.set(userId, {
        ws,
        userId,
        username,
        roomId,
        isSpeaker
    });
    
    // Add to room
    if (!rooms.has(roomId)) {
        rooms.set(roomId, new Set());
    }
    rooms.get(roomId).add(userId);
    
    console.log(`User ${username} (${userId}) joined room ${roomId}`);
    
    // Notify others in the room
    broadcastToRoom(roomId, {
        type: 'user-joined',
        userId,
        username,
        isSpeaker
    }, userId);
    
    // Send current participants to the new user
    const participants = [];
    rooms.get(roomId).forEach(id => {
        if (id !== userId && clients.has(id)) {
            participants.push({
                userId: id,
                username: clients.get(id).username,
                isSpeaker: clients.get(id).isSpeaker
            });
        }
    });
    
    ws.send(JSON.stringify({
        type: 'participants',
        participants
    }));
}

// Handle user leaving a room
function handleLeave(ws, data) {
    const { roomId, userId } = data;
    
    // Remove from room
    if (rooms.has(roomId)) {
        rooms.get(roomId).delete(userId);
        if (rooms.get(roomId).size === 0) {
            rooms.delete(roomId);
        }
    }
    
    // Remove client
    const client = clients.get(userId);
    if (client) {
        clients.delete(userId);
    }
    
    console.log(`User ${userId} left room ${roomId}`);
    
    // Notify others
    broadcastToRoom(roomId, {
        type: 'user-left',
        userId
    });
}

// Handle mute toggle
function handleMute(ws, data) {
    const { roomId, userId, isMuted } = data;
    
    broadcastToRoom(roomId, {
        type: 'user-muted',
        userId,
        isMuted
    });
}

// Handle hand raise
function handleHand(ws, data) {
    const { roomId, userId, raised } = data;
    
    broadcastToRoom(roomId, {
        type: 'user-hand',
        userId,
        raised
    });
}

// Handle WebRTC signaling
function handleSignaling(ws, data) {
    const { targetUserId, type, offer, answer, candidate } = data;
    
    if (targetUserId && clients.has(targetUserId)) {
        const targetClient = clients.get(targetUserId);
        targetClient.ws.send(JSON.stringify(data));
    }
}

// Handle disconnect
function handleDisconnect(userId) {
    const client = clients.get(userId);
    
    if (client) {
        const { roomId } = client;
        
        // Remove from room
        if (rooms.has(roomId)) {
            rooms.get(roomId).delete(userId);
            if (rooms.get(roomId).size === 0) {
                rooms.delete(roomId);
            }
        }
        
        // Notify others
        broadcastToRoom(roomId, {
            type: 'user-left',
            userId
        });
        
        clients.delete(userId);
        console.log(`User ${userId} disconnected`);
    }
}

// Broadcast to all users in a room (except sender)
function broadcastToRoom(roomId, message, excludeUserId = null) {
    if (!rooms.has(roomId)) return;
    
    const messageStr = JSON.stringify(message);
    
    rooms.get(roomId).forEach(userId => {
        if (userId !== excludeUserId && clients.has(userId)) {
            const client = clients.get(userId);
            try {
                client.ws.send(messageStr);
            } catch (error) {
                console.error(`Error sending to user ${userId}:`, error);
            }
        }
    });
}

// Start server
server.listen(PORT, HOST, () => {
    console.log(`WebSocket server running on ${HOST}:${PORT}`);
});

// Handle process termination
process.on('SIGTERM', () => {
    console.log('Shutting down WebSocket server...');
    wss.clients.forEach(client => client.close());
    server.close(() => {
        console.log('Server closed');
        process.exit(0);
    });
});
