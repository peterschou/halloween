/* game.js
   Core WebRTC engine for The Scare Path.
   Hosts and peers use this file to exchange SDP/ICE via the PHP signaling server.
*/

const SIGNAL_API = 'signal.php';
const ICE_SERVERS = [{ urls: 'stun:stun.l.google.com:19302' }];
const DEBUG_LOG = true;
// --- Constants for safe zone and proximity ---
const SAFE_ZONE_X = 12; // Walkers start at x=5, safe zone is x<=12
const WALKER_START_X = 5;
const SCARER_START_X = 95;
const PROXIMITY_DISTANCE = 14;
const peerConnections = new Map();
const pendingHostIce = new Map();
let hostState = {
  instanceId: null,
  roomId: null,
  hostPeerId: null,
  polling: false,
  connectedPeers: new Set(),
  position: { x: 10, y: 10, role: 'host' },
};
let localPeerState = {
  instanceId: null,
  roomId: null,
  peerId: null,
  pc: null,
  channel: null,
  polling: false,
  pendingIce: [],
  joinTimeout: null,
};
const gameState = { players: {}, scare: null };

function genPeerId() {
  return 'peer-' + Math.random().toString(36).slice(2, 12) + '-' + Date.now();
}

function debug(...args) {
  if (!DEBUG_LOG) return;
  console.debug('[ScarePath]', ...args);
  const log = document.getElementById('debugLog');
  if (log) {
    const newText = args.map(arg => {
      if (arg instanceof Error) {
        return `${arg.name}: ${arg.message}\n${arg.stack || ''}`;
      }
      if (typeof arg === 'object') {
        try {
          return JSON.stringify(arg);
        } catch (err) {
          return String(arg);
        }
      }
      return String(arg);
    }).join(' ') + '\n';
    const isAtBottom = log.scrollHeight - log.clientHeight - log.scrollTop < 24;
    log.textContent += newText;
    if (isAtBottom) {
      log.scrollTop = log.scrollHeight;
    }
  }
}

async function apiPost(body) {
  debug('apiPost ->', body);
  const response = await fetch(SIGNAL_API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const text = await response.text();
  if (!response.ok) {
    let errorMsg = response.statusText;
    try {
      const error = JSON.parse(text);
      errorMsg = error?.error || errorMsg;
    } catch (err) {
      errorMsg = text || errorMsg;
    }
    debug('apiPost error response', text);
    throw new Error(errorMsg);
  }
  try {
    const data = JSON.parse(text);
    debug('apiPost <-', data);
    return data;
  } catch (err) {
    debug('apiPost invalid JSON', text);
    throw err;
  }
}

function updateConnectionBadge(state) {
  const badge = document.getElementById('connectionBadge');
  if (!badge) return;
  badge.textContent = state === 'connected' ? 'Connected' : state === 'online' ? 'Host ready' : state === 'waiting' ? 'Waiting' : state === 'error' ? 'Error' : 'Offline';
  badge.className = `status-badge ${state || 'offline'}`;
}

function showStatus(message, state = 'offline') {
  const status = document.getElementById('statusMessage');
  if (status) status.textContent = message;
  updateConnectionBadge(state);
}

function updatePathUI() {
  const path = document.getElementById('gamePath');
  if (!path) return;
  if (localPeerState.channel) {
    path.classList.add('connected');
  }
}

function renderPlayerList(players) {
  const list = document.getElementById('peerList');
  if (!list) return;
  list.innerHTML = Object.keys(players).map(peerId => {
    const player = players[peerId];
    const role = player.role || (peerId === 'host' ? 'host' : 'walker');
    return `<li>${peerId} (${role}) – x:${player.x.toFixed(0)}, y:${player.y.toFixed(0)}</li>`;
  }).join('');
}

function displayScare(effect) {
  const overlay = document.getElementById('scareOverlay');
  if (!overlay) return;
  overlay.textContent = effect || 'BOO!';
  overlay.classList.add('active');
  setTimeout(() => overlay.classList.remove('active'), 1600);
}

function renderPlayerLayer(players) {
  const layer = document.getElementById('playerLayer');
  if (!layer) return;
  layer.innerHTML = '';
  const playerIds = Object.keys(players);
  const selfId = localPeerState.peerId || (hostState.hostPeerId === 'host' ? 'host' : null);
  const self = selfId ? players[selfId] : null;
  let visibleIds = new Set();
  if (self) {
    visibleIds.add(selfId);
    // Only show others if within proximity
    for (const peerId of playerIds) {
      if (peerId === selfId) continue;
      const other = players[peerId];
      if (!other) continue;
      const dx = self.x - other.x;
      const dy = self.y - other.y;
      const distance = Math.sqrt(dx * dx + dy * dy);
      if (distance < PROXIMITY_DISTANCE) {
        visibleIds.add(peerId);
      }
    }
  }
  for (const peerId of playerIds) {
    if (!visibleIds.has(peerId)) continue;
    const player = players[peerId];
    if (!player || typeof player.x !== 'number' || typeof player.y !== 'number') continue;
    const role = player.role || (peerId === 'host' ? 'host' : 'walker');
    const avatar = document.createElement('div');
    avatar.className = `player-avatar ${role}` + (player.x <= SAFE_ZONE_X && role === 'walker' ? ' near' : '');
    avatar.style.left = `${Math.min(96, Math.max(2, player.x))}%`;
    avatar.style.top = `${Math.min(88, Math.max(2, player.y))}%`;
    avatar.dataset.label = role === 'host' ? 'H' : 'W';
    avatar.textContent = role === 'host' ? '🕷' : '👻';
    layer.appendChild(avatar);
  }
}

function computeProximity(playerArray) {
  const nearSet = new Set();
  for (let i = 0; i < playerArray.length; i++) {
    for (let j = i + 1; j < playerArray.length; j++) {
      const a = playerArray[i];
      const b = playerArray[j];
      const dx = a.data.x - b.data.x;
      const dy = a.data.y - b.data.y;
      const distance = Math.sqrt(dx * dx + dy * dy);
      if (distance < 14) {
        nearSet.add(a.id);
        nearSet.add(b.id);
      }
    }
  }
  return nearSet;
}

async function initHost(roomId) {
  hostState.roomId = roomId;
  hostState.hostPeerId = 'host';
  const hostPeerElem = document.getElementById('hostPeerId');
  const hostRoomElem = document.getElementById('hostRoomId');
  if (hostPeerElem) hostPeerElem.textContent = hostState.hostPeerId;
  if (hostRoomElem) hostRoomElem.textContent = hostState.roomId;
  hostState.position = { x: SCARER_START_X, y: 50, role: 'host', username: window.SCARER_USERNAME || 'host' };
  gameState.players[hostState.hostPeerId] = hostState.position;
  renderGameState({ players: gameState.players });
  updatePathUI();
  if (!hostState.instanceId) {
    await createRoomOnServer(roomId);
  }
  // Start polling only after instanceId is set
  if (hostState.instanceId) {
    debug('Host polling loop starting', { instanceId: hostState.instanceId });
    hostState.polling = true;
    pollHostSignals();
    setInterval(pollHostSignals, 1500);
  }
}

async function createRoomOnServer(roomId) {
  try {
    const payload = {
      action: 'create_room',
      room_id: roomId,
      host_user_id: window.SCARER_USER_ID || 0,
    };
    debug('host createRoom payload', payload);
    const result = await apiPost(payload);
    debug('host createRoom result', result);
    hostState.instanceId = result.instance_id;
    showStatus(`Host ready. Room ${roomId} registered.`, 'online');
  } catch (error) {
    debug('host createRoom error', error);
    showStatus(`Failed to create room: ${error.message}`, 'error');
  }
}

function buildHostPeerConnection(peerId) {
  const pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
  const record = { pc, channel: null };
  peerConnections.set(peerId, record);
  pc.onicecandidate = async event => {
    debug('host onicecandidate', peerId, event.candidate);
    if (!event.candidate) return;
    await sendSignal({
      instance_id: hostState.instanceId,
      from_peer: hostState.hostPeerId,
      to_peer: peerId,
      kind: 'ice',
      payload: event.candidate.toJSON(),
    });
  };
  pc.onconnectionstatechange = () => {
    debug('host connection state', peerId, pc.connectionState);
  };
  pc.ondatachannel = event => {
    const channel = event.channel;
    record.channel = channel;
    channel.onmessage = msg => handleHostMessage(peerId, msg.data);
    channel.onopen = () => {
      console.log('Host connected to peer', peerId);
      hostState.connectedPeers.add(peerId);
      showStatus(`Connected peers: ${hostState.connectedPeers.size}`, 'connected');
      // Force info bar update on host peer connect
      renderGameState({ players: gameState.players });
    };
  };

  const queued = pendingHostIce.get(peerId);
  if (Array.isArray(queued)) {
    queued.forEach(async candidate => {
      try {
        await pc.addIceCandidate(new RTCIceCandidate(candidate));
      } catch (ignored) {
        console.warn('Host queued ice failed', ignored);
      }
    });
    pendingHostIce.delete(peerId);
  }

  return pc;
}

async function pollHostSignals() {
  if (!hostState.polling || !hostState.instanceId) {
    debug('host poll skipped', { polling: hostState.polling, instanceId: hostState.instanceId });
  }
  debug('host poll starting', { instanceId: hostState.instanceId, hostPeerId: hostState.hostPeerId });
  try {
    const result = await apiPost({
      action: 'poll_signals',
      instance_id: hostState.instanceId,
      target_peer: hostState.hostPeerId,
    });
    debug('host poll signals', result.signals?.length);
    if (!Array.isArray(result.signals) || result.signals.length === 0) {
      return;
    }
    const processed = [];
    for (const signal of result.signals) {
      const fromPeer = signal.from_peer;
      if (signal.kind === 'offer') {
        await handleOfferFromPeer(fromPeer, signal.payload);
      }
      if (signal.kind === 'ice') {
        await handleIceFromPeer(fromPeer, signal.payload);
      }
      processed.push(signal.id);
    }
    if (processed.length) {
      await consumeSignals(processed);
    }
  } catch (error) {
    console.warn('Host poll failed:', error.message);
  }
}

async function handleOfferFromPeer(peerId, offer) {
  const pc = buildHostPeerConnection(peerId);
  await pc.setRemoteDescription(new RTCSessionDescription(offer));
  const answer = await pc.createAnswer();
  await pc.setLocalDescription(answer);
  const answerDesc = pc.localDescription || answer;
  await sendSignal({
    instance_id: hostState.instanceId,
    from_peer: hostState.hostPeerId,
    to_peer: peerId,
    kind: 'answer',
    payload: { type: answerDesc.type, sdp: answerDesc.sdp },
  });
}

async function handleIceFromPeer(peerId, candidate) {
  const record = peerConnections.get(peerId);
  if (!record) {
    const queue = pendingHostIce.get(peerId) || [];
    queue.push(candidate);
    pendingHostIce.set(peerId, queue);
    return;
  }
  try {
    await record.pc.addIceCandidate(new RTCIceCandidate(candidate));
  } catch (err) {
    console.warn('Host addIceCandidate failed', err);
  }
}

async function initPeer(roomId) {
  localPeerState.roomId = roomId;
  localPeerState.peerId = genPeerId();
  localPeerState.pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
  localPeerState.pc.onicegatheringstatechange = () => {
    debug('peer ice gathering', localPeerState.pc.iceGatheringState);
  };
  localPeerState.pc.onconnectionstatechange = () => {
    debug('peer connection state', localPeerState.pc.connectionState);
    if (localPeerState.pc.connectionState === 'connected') {
      showStatus('Connected to host. Ready for scares.', 'connected');
      clearTimeout(localPeerState.joinTimeout);
      // Force info bar update on connect
      renderGameState({ players: gameState.players });
    }
  };
  localPeerState.channel = localPeerState.pc.createDataChannel('scarepath');
  localPeerState.channel.onopen = () => {
    debug('peer data channel open');
    showStatus('Connected to host. Ready for scares.', 'connected');
    clearTimeout(localPeerState.joinTimeout);
    updatePathUI();
    // Force info bar update on data channel open
    renderGameState({ players: gameState.players });
  };
  localPeerState.channel.onmessage = event => handlePeerMessage(event.data);
  localPeerState.pc.onicecandidate = async event => {
    debug('peer onicecandidate', event.candidate);
    if (!event.candidate) return;
    await sendSignal({
      instance_id: localPeerState.instanceId,
      from_peer: localPeerState.peerId,
      to_peer: 'host',
      kind: 'ice',
      payload: event.candidate.toJSON(),
    });
  };
  // Set initial walker position in safe zone, include username
  // Use session username if available, else prompt, else peerId
  let walkerName = window.WALKER_USERNAME;
  if (!walkerName || walkerName === 'null') {
    walkerName = prompt('Enter your name for the game:', 'Walker') || localPeerState.peerId;
    window.WALKER_USERNAME = walkerName;
  }
  gameState.players[localPeerState.peerId] = { x: WALKER_START_X, y: 50, role: 'walker', username: walkerName };
  if (!gameState.players[localPeerState.peerId].username) {
    gameState.players[localPeerState.peerId].username = walkerName;
  }
  await createOfferToHost();
  localPeerState.joinTimeout = window.setTimeout(() => {
    if (!localPeerState.channel || localPeerState.channel.readyState !== 'open') {
      showStatus('Still waiting for host answer...', 'waiting');
    }
  }, 12000);
  localPeerState.polling = true;
  pollPeerSignals();
  setInterval(pollPeerSignals, 1500);
}

async function createOfferToHost() {
  try {
    const offer = await localPeerState.pc.createOffer();
    debug('peer created offer', offer);
    await localPeerState.pc.setLocalDescription(offer);
    const result = await apiPost({
      action: 'send_signal',
      instance_id: localPeerState.instanceId,
      from_peer: localPeerState.peerId,
      to_peer: 'host',
      kind: 'offer',
      payload: { type: offer.type, sdp: offer.sdp },
    });
    debug('peer offer result', result);
    showStatus('Offer sent to host, waiting for answer...', 'waiting');
  } catch (error) {
    debug('createOfferToHost error', error);
    console.error('createOfferToHost error', error);
    showStatus(`Offer error: ${error?.message || 'unknown error'}`, 'error');
  }
}

async function pollPeerSignals() {
  if (!localPeerState.polling || !localPeerState.instanceId) return;
  try {
    const result = await apiPost({
      action: 'poll_signals',
      instance_id: localPeerState.instanceId,
      target_peer: localPeerState.peerId,
    });
    debug('peer poll signals', result.signals?.length);
    if (!Array.isArray(result.signals) || result.signals.length === 0) {
      return;
    }
    const processed = [];
    for (const signal of result.signals) {
      if (signal.kind === 'answer') {
        await localPeerState.pc.setRemoteDescription(new RTCSessionDescription(signal.payload));
        showStatus('Answer received from Host. Finalizing connection...', 'waiting');
        if (localPeerState.pendingIce.length > 0) {
          for (const candidate of localPeerState.pendingIce) {
            try {
              await localPeerState.pc.addIceCandidate(new RTCIceCandidate(candidate));
            } catch (err) {
              console.warn('Peer queued addIceCandidate failed', err);
            }
          }
          localPeerState.pendingIce = [];
        }
      }
      if (signal.kind === 'ice') {
        if (localPeerState.pc.remoteDescription && localPeerState.pc.remoteDescription.type) {
          try {
            await localPeerState.pc.addIceCandidate(new RTCIceCandidate(signal.payload));
          } catch (err) {
            console.warn('Peer addIceCandidate failed', err);
          }
        } else {
          localPeerState.pendingIce.push(signal.payload);
        }
      }
      processed.push(signal.id);
    }
    if (processed.length) {
      await consumeSignals(processed);
    }
  } catch (error) {
    console.warn('Peer poll failed:', error.message);
  }
}

async function sendSignal(signal) {
  const result = await apiPost(Object.assign({ action: 'send_signal' }, signal));
  return result;
}

async function consumeSignals(signalIds) {
  if (!Array.isArray(signalIds) || signalIds.length === 0) return;
  await apiPost({ action: 'consume_signals', signal_ids: signalIds });
}

function handleHostMessage(peerId, rawData) {
  let msg;
  try {
    msg = JSON.parse(rawData);
  } catch (err) {
    return;
  }
  if (msg.type === 'movement') {
    msg.payload.role = msg.payload.role || 'walker';
    // Always set username for walker
    if (!msg.payload.username) {
      msg.payload.username = window.WALKER_USERNAME || peerId;
    }
    gameState.players[peerId] = msg.payload;
    // Ensure host is always present in player list
    if (hostState.hostPeerId && !gameState.players[hostState.hostPeerId]) {
      gameState.players[hostState.hostPeerId] = hostState.position;
    }
    renderGameState({ players: gameState.players });
    broadcastHostState();
  }
}

function broadcastHostState() {
  // Always ensure the host is present as the correct peerId in gameState.players
  if (hostState.hostPeerId) {
    // Always set username for host
    if (!hostState.position.username) {
      hostState.position.username = window.SCARER_USERNAME || hostState.hostPeerId;
    }
    gameState.players[hostState.hostPeerId] = hostState.position;
    // Remove any stale 'host' key if hostPeerId is not literally 'host'
    if (hostState.hostPeerId !== 'host' && gameState.players.host) {
      delete gameState.players.host;
    }
  }
  const payload = {
    type: 'gameState',
    payload: {
      players: gameState.players,
      updatedAt: Date.now(),
    },
  };
  const message = JSON.stringify(payload);
  peerConnections.forEach(({ channel }) => {
    if (channel && channel.readyState === 'open') {
      channel.send(message);
    }
  });
}

function handlePeerMessage(rawData) {
  let msg;
  try {
    msg = JSON.parse(rawData);
  } catch (err) {
    return;
  }
  if (msg.type === 'gameState') {
    renderGameState(msg.payload);
  }
  if (msg.type === 'scare') {
    displayScare(msg.payload.effect);
  }
}

function renderGameState(payload) {
  if (!payload) return;
  console.log('[game.js renderGameState] called with:', payload);
  gameState.players = payload.players || {};
  window.gameState = gameState; // ensure global for info bar
  const selfId = localPeerState.peerId || (hostState.hostPeerId === 'host' ? 'host' : null);
  const self = selfId ? gameState.players[selfId] : null;
  if (self) {
    // Keep a self marker available for future extensions
    const marker = document.getElementById('playerMarker');
    if (marker) {
      marker.style.left = `${Math.min(96, Math.max(2, self.x))}%`;
      marker.style.top = `${Math.min(88, Math.max(2, self.y))}%`;
    }
  }
  renderPlayerList(gameState.players);
  renderPlayerLayer(gameState.players);
}

function sendMovement(deltaX, deltaY) {
  const selfId = localPeerState.peerId || (hostState.hostPeerId === 'host' ? 'host' : null);
  const selfRole = hostState.hostPeerId === 'host' && !localPeerState.peerId ? 'host' : 'walker';
  const current = selfId && gameState.players[selfId] ? gameState.players[selfId] : { x: 0, y: 0 };
  const newX = clampMovement(current.x + deltaX, selfRole, 'x', current.x);
  const newY = clampMovement(current.y + deltaY, selfRole, 'y', current.y);
  let username = (selfRole === 'host') ? (window.SCARER_USERNAME || selfId) : (window.WALKER_USERNAME || selfId);
  // If walker, prompt if not set
  if (selfRole === 'walker' && (!username || username === 'null')) {
    username = prompt('Enter your name for the game:', 'Walker') || selfId;
    window.WALKER_USERNAME = username;
  }
  const payload = {
    x: newX,
    y: newY,
    timestamp: Date.now(),
    role: selfRole,
    username: username,
  };
  const movement = { type: 'movement', payload };

  if (localPeerState.channel && localPeerState.channel.readyState === 'open') {
    localPeerState.channel.send(JSON.stringify(movement));
    return;
  }

  if (hostState.hostPeerId === 'host' && hostState.instanceId) {
    hostState.position = payload;
    gameState.players[hostState.hostPeerId] = payload;
    renderGameState({ players: gameState.players });
    broadcastHostState();
  }
}

function clampMovement(value, role, axis, currentX) {
  if (role === 'host' && axis === 'x') {
    // Scarer cannot enter safe zone
    return Math.max(SAFE_ZONE_X + 1, Math.min(100, value));
  }
  if (role === 'walker' && axis === 'x') {
    // Walker can go anywhere
    return Math.max(0, Math.min(100, value));
  }
  // y axis
  return Math.max(0, Math.min(100, value));
}

async function sendScare(effect) {
  if (!hostState.instanceId || !hostState.hostPeerId) return;
  const eventData = JSON.stringify({ type: 'scare', payload: { effect } });
  peerConnections.forEach(({ channel }) => {
    if (channel && channel.readyState === 'open') {
      channel.send(eventData);
    }
  });
  displayScare(effect);
}

function isGameplayPage() {
  return !!document.getElementById('gamePath') &&
         !!document.getElementById('hostPeerId') &&
         !!document.getElementById('hostRoomId');
}

function bootstrapLobby() {
  let createButton = document.getElementById('createRoomBtn');
  const joinButtons = document.querySelectorAll('.join-room');
  if (createButton) {
    // Remove all previous event listeners by replacing with a clone
    const newBtn = createButton.cloneNode(true);
    createButton.parentNode.replaceChild(newBtn, createButton);
    createButton = newBtn;
  }
  if (createButton && isGameplayPage()) {
    // Only on gameplay page: allow host to start game
    createButton.addEventListener('click', async () => {
      const roomId = document.getElementById('roomInput').value.trim();
      if (!roomId) {
        showStatus('Enter a room code first.', 'error');
        return;
      }
      await initHost(roomId);
      const hostControls = document.getElementById('hostControls');
      if (hostControls) hostControls.classList.remove('hidden');
    });
  } else if (createButton) {
    // In the lobby or any other page: only redirect after room creation
    createButton.addEventListener('click', async () => {
      const roomId = document.getElementById('roomInput').value.trim();
      if (!roomId) {
        alert('Enter a room code first.');
        return;
      }
      try {
        const payload = {
          action: 'create_room',
          room_id: roomId,
          host_user_id: window.SCARER_USER_ID || 0,
        };
        const result = await apiPost(payload);
        window.location.href = `gamepanel.php?room=${encodeURIComponent(roomId)}&instance=${encodeURIComponent(result.instance_id)}`;
      } catch (err) {
        alert('Failed to create room: ' + (err?.message || err));
      }
    });
  }
  // ...existing code for join buttons and movement controls...
}

window.addEventListener('load', bootstrapLobby);


if (isGameplayPage()) {
  // On gameplay page, initialize host or peer based on URL params
  const urlParams = new URLSearchParams(window.location.search);
  const roomId = window.GAME_ROOM_ID || urlParams.get('room');
  const instanceId = window.GAME_INSTANCE_ID || urlParams.get('instance');
  debug('Gameplay page init:', { roomId, instanceId, user: window.SCARER_USER_ID });
  if (window.SCARER_USER_ID && roomId && instanceId) {
    // Host
    hostState.instanceId = Number(instanceId);
    debug('Host initializing with', { roomId, instanceId });
    initHost(roomId);
    updateConnectionBadge('online');
  } else if (roomId && instanceId) {
    // Peer
    localPeerState.instanceId = Number(instanceId);
    debug('Peer initializing with', { roomId, instanceId });
    initPeer(roomId);
    updateConnectionBadge('waiting');
  } else {
    debug('Missing room or instance id for gameplay init', { roomId, instanceId });
    updateConnectionBadge('error');
  }

  // Add arrow key movement for both host and walkers
  window.addEventListener('keydown', function(e) {
    if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA')) return;
    let dx = 0, dy = 0;
    switch (e.key) {
      case 'ArrowLeft': dx = -4; break;
      case 'ArrowRight': dx = 4; break;
      case 'ArrowUp': dy = -4; break;
      case 'ArrowDown': dy = 4; break;
      default: return;
    }
    sendMovement(dx, dy);
    e.preventDefault();
  });
}

document.addEventListener('click', function(event) {
    const target = event.target;
    if (target.matches('.join-room')) {
      const roomId = target.dataset.roomId;
      const instanceId = target.dataset.instanceId;
      window.location.href = `gamepanel.php?room=${encodeURIComponent(roomId)}&instance=${encodeURIComponent(instanceId)}`;
    }
  });


// ...existing code...


