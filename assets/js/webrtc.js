/**
 * WebRTC Manager for Voice Chat
 */

class WebRTCManager {
    constructor() {
        this.localStream = null;
        this.peerConnections = {};
        this.configuration = {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: 'stun:stun1.l.google.com:19302' }
            ]
        };
    }
    
    async getLocalStream() {
        if (this.localStream) return this.localStream;
        
        try {
            this.localStream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true
                },
                video: false
            });
            return this.localStream;
        } catch (error) {
            console.error('Failed to get local stream:', error);
            throw error;
        }
    }
    
    stopLocalStream() {
        if (this.localStream) {
            this.localStream.getTracks().forEach(track => track.stop());
            this.localStream = null;
        }
    }
    
    createPeerConnection(userId) {
        const pc = new RTCPeerConnection(this.configuration);
        
        // Add local tracks
        if (this.localStream) {
            this.localStream.getTracks().forEach(track => {
                pc.addTrack(track, this.localStream);
            });
        }
        
        // Handle remote stream
        pc.ontrack = (event) => {
            console.log('Received remote track from', userId);
            this.playRemoteAudio(userId, event.streams[0]);
        };
        
        // Handle ICE candidates
        pc.onicecandidate = (event) => {
            if (event.candidate && window.ws) {
                window.ws.send(JSON.stringify({
                    type: 'ice-candidate',
                    targetUserId: userId,
                    candidate: event.candidate
                }));
            }
        };
        
        // Handle connection state changes
        pc.onconnectionstatechange = () => {
            console.log(`Connection state with ${userId}:`, pc.connectionState);
            
            if (pc.connectionState === 'disconnected' || pc.connectionState === 'failed') {
                this.closePeerConnection(userId);
            }
        };
        
        this.peerConnections[userId] = pc;
        return pc;
    }
    
    async createOffer(userId) {
        const pc = this.createPeerConnection(userId);
        
        try {
            const offer = await pc.createOffer();
            await pc.setLocalDescription(offer);
            
            if (window.ws) {
                window.ws.send(JSON.stringify({
                    type: 'offer',
                    targetUserId: userId,
                    offer: offer
                }));
            }
        } catch (error) {
            console.error('Failed to create offer:', error);
        }
    }
    
    async handleOffer(data) {
        const { userId, offer } = data;
        
        const pc = this.createPeerConnection(userId);
        
        try {
            await pc.setRemoteDescription(new RTCSessionDescription(offer));
            
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            
            if (window.ws) {
                window.ws.send(JSON.stringify({
                    type: 'answer',
                    targetUserId: userId,
                    answer: answer
                }));
            }
        } catch (error) {
            console.error('Failed to handle offer:', error);
        }
    }
    
    async handleAnswer(data) {
        const { userId, answer } = data;
        
        if (this.peerConnections[userId]) {
            try {
                await this.peerConnections[userId].setRemoteDescription(
                    new RTCSessionDescription(answer)
                );
            } catch (error) {
                console.error('Failed to handle answer:', error);
            }
        }
    }
    
    async handleIceCandidate(data) {
        const { userId, candidate } = data;
        
        if (this.peerConnections[userId]) {
            try {
                await this.peerConnections[userId].addIceCandidate(
                    new RTCIceCandidate(candidate)
                );
            } catch (error) {
                console.error('Failed to handle ICE candidate:', error);
            }
        }
    }
    
    playRemoteAudio(userId, stream) {
        // Remove existing audio element if any
        const existingAudio = document.getElementById(`audio-${userId}`);
        if (existingAudio) {
            existingAudio.remove();
        }
        
        // Create new audio element
        const audio = document.createElement('audio');
        audio.id = `audio-${userId}`;
        audio.srcObject = stream;
        audio.autoplay = true;
        audio.playsInline = true;
        
        // Add to DOM (hidden)
        audio.style.display = 'none';
        document.body.appendChild(audio);
    }
    
    closePeerConnection(userId) {
        if (this.peerConnections[userId]) {
            this.peerConnections[userId].close();
            delete this.peerConnections[userId];
        }
        
        // Remove audio element
        const audio = document.getElementById(`audio-${userId}`);
        if (audio) {
            audio.remove();
        }
    }
    
    closeAllConnections() {
        Object.keys(this.peerConnections).forEach(userId => {
            this.closePeerConnection(userId);
        });
    }
    
    setMuted(muted) {
        if (this.localStream) {
            this.localStream.getAudioTracks().forEach(track => {
                track.enabled = !muted;
            });
        }
    }
}

// Export global instance
window.webrtcManager = new WebRTCManager();
