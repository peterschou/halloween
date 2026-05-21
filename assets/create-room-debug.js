document.addEventListener('DOMContentLoaded', function() {
  var btn = document.getElementById('createRoomBtn');
  if (btn) {
    btn.onclick = async function() {
      var input = document.getElementById('roomInput');
      var roomId = input && input.value.trim();
      if (!roomId) {
        alert('Please enter a room code.');
        return;
      }
      btn.disabled = true;
      btn.textContent = 'Creating...';
      try {
        console.log('[CreateRoom] Sending request', roomId, window.SCARER_USER_ID);
        const resp = await fetch('signal.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'create_room',
            room_id: roomId,
            host_user_id: window.SCARER_USER_ID || 0
          })
        });
        const text = await resp.text();
        console.log('[CreateRoom] Raw response:', text);
        let data;
        try { data = JSON.parse(text); } catch (e) { data = null; }
        if (data && data.instance_id) {
          window.location.reload();
        } else {
          alert('Failed to create room: ' + (data && data.error ? data.error : text || 'Unknown error'));
        }
      } catch (e) {
        alert('Error: ' + (e.message || e));
      } finally {
        btn.disabled = false;
        btn.textContent = 'Create Instance';
      }
    };
  }
});
