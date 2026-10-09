'use strict';
(() => {
  const rearLabel = label => /\b(rear|back|environment|achter(?:camera|zijde)?|rück(?:kamera|seite)?|arrière)\b/i.test(label)
    && !/\b(front|user|voor(?:camera|zijde)?|infrared|ir)\b/i.test(label);
  async function openRearCamera() {
    const media = navigator.mediaDevices;
    const devices = await media.enumerateDevices();
    const rear = devices.find(d => d.kind === 'videoinput' && rearLabel(d.label));
    const supported = media.getSupportedConstraints();
    if (!rear && !supported.facingMode) throw new Error('rear-unavailable');
    const next = await media.getUserMedia({audio: false, video: {
      ...(rear ? {deviceId: {exact: rear.deviceId}} : {facingMode: {exact: 'environment'}}),
      width: {ideal: 1920}, height: {ideal: 1080}
    }});
    const track = next.getVideoTracks()[0];
    const settings = track?.getSettings();
    // Do not show a stream unless its rear-facing identity is confirmed.
    const confirmed = settings?.facingMode === 'environment'
      || (!settings?.facingMode && (rearLabel(track?.label || '')
        || (rear && settings?.deviceId === rear.deviceId)));
    if (!confirmed) {
      next.getTracks().forEach(t => t.stop());
      throw new Error('rear-unavailable');
    }
    return next;
  }
  document.querySelectorAll('input[type="file"][name="identity_document"]').forEach(input => {
    const open = document.createElement('button');
    open.type = 'button'; open.className = 'button button-secondary id-camera-open'; open.textContent = '📷 Foto van ID nemen';
    const selection = document.createElement('span'); selection.className = 'help id-camera-selection'; selection.setAttribute('role', 'status');
    input.after(open, selection);
    const dialog = document.createElement('dialog'); dialog.className = 'id-camera-dialog';
    dialog.setAttribute('aria-label', 'Foto van identiteitsdocument');
    dialog.innerHTML = `<h2>Foto van identiteitsdocument</h2><p>Leg het document goed in beeld. Controleer na het nemen of alles leesbaar is.</p><p>Alleen de achtercamera wordt gebruikt.</p><video autoplay muted playsinline></video><canvas hidden></canvas><p class="id-camera-message" role="status"></p><div class="actions"><button type="button" class="button" data-action="capture">Foto nemen</button><button type="button" class="button button-secondary" data-action="retake" hidden>Opnieuw nemen</button><button type="button" class="button" data-action="use" hidden>Deze foto gebruiken</button><button type="button" class="button button-secondary" data-action="cancel">Annuleren</button></div>`;
    document.body.append(dialog);
    const video = dialog.querySelector('video'), canvas = dialog.querySelector('canvas'), message = dialog.querySelector('[role="status"]');
    const capture = dialog.querySelector('[data-action="capture"]'), retake = dialog.querySelector('[data-action="retake"]'), use = dialog.querySelector('[data-action="use"]');
    let stream = null, generation = 0;
    function stop() { generation++; stream?.getTracks().forEach(t => t.stop()); stream = null; video.srcObject = null; }
    function clearPreview() { canvas.width = 0; canvas.height = 0; canvas.hidden = true; use.hidden = true; retake.hidden = true; }
    async function start() {
      stop(); clearPreview(); video.hidden = false; capture.hidden = false; capture.disabled = true;
      const request = generation;
      message.textContent = 'Camera openen… Geef toestemming als de browser dit vraagt.';
      try {
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) throw new Error('unsupported');
        const next = await openRearCamera();
        if (request !== generation || !dialog.open) { next.getTracks().forEach(t => t.stop()); return; }
        stream = next; video.srcObject = next; await video.play();
        if (request !== generation || !dialog.open) return;
        capture.disabled = false;
        message.textContent = 'Achtercamera actief. Breng het document volledig in beeld.';
      } catch (error) {
        if (request !== generation || !dialog.open) return;
        stop();
        message.textContent = error.name === 'NotAllowedError'
          ? 'Cameratoegang geblokkeerd. Sta de camera toe in de browser en in de Windows-privacyinstellingen, en probeer opnieuw. Je kunt ook een bestand kiezen.'
          : 'Achtercamera niet beschikbaar of niet herkend. De voorcamera wordt niet gebruikt. Controleer de cameratoegang en probeer opnieuw, of kies een bestaand bestand.';
        capture.hidden = true; retake.hidden = false;
      }
    }
    open.addEventListener('click', () => { dialog.showModal(); start(); });
    capture.addEventListener('click', () => {
      if (!video.videoWidth || !video.videoHeight) return;
      const scale = Math.min(1, 2000 / Math.max(video.videoWidth, video.videoHeight));
      canvas.width = Math.round(video.videoWidth * scale); canvas.height = Math.round(video.videoHeight * scale);
      canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
      stop(); video.hidden = true; canvas.hidden = false; capture.hidden = true; retake.hidden = false; use.hidden = false;
      message.textContent = 'Is de foto scherp en leesbaar? Kies Deze foto gebruiken.';
    });
    retake.addEventListener('click', () => start());
    use.addEventListener('click', () => {
      const request = generation; use.disabled = true;
      canvas.toBlob(blob => {
        use.disabled = false;
        if (!dialog.open || request !== generation) return;
        if (!blob) { message.textContent = 'Foto maken mislukt. Probeer opnieuw.'; return; }
        try {
          const transfer = new DataTransfer(); transfer.items.add(new File([blob], 'identiteitsdocument.jpg', {type: 'image/jpeg'}));
          input.files = transfer.files; input.dispatchEvent(new Event('change', {bubbles: true}));
          selection.textContent = 'Foto geselecteerd. Sla het formulier op om deze te uploaden.';
          dialog.close();
        } catch { message.textContent = 'De foto kon niet worden gekoppeld. Kies een bestaand bestand via Bestand kiezen.'; }
      }, 'image/jpeg', 0.9);
    });
    dialog.querySelector('[data-action="cancel"]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { stop(); clearPreview(); open.focus(); });
    input.addEventListener('change', () => { selection.textContent = input.files.length ? `Geselecteerd: ${input.files[0].name}` : ''; });
    window.addEventListener('pagehide', () => { stop(); clearPreview(); });
    document.addEventListener('visibilitychange', () => { if (document.hidden && dialog.open) dialog.close(); });
  });
})();
