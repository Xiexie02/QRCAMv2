(() => {
    'use strict';

    const message = document.querySelector('#checkin-message');
    const startButton = document.querySelector('#checkin-start');
    const rescanButton = document.querySelector('#checkin-rescan');
    let csrf = '';
    let scanner = null;
    let busy = false;

    async function request(action, options = {}) {
        const headers = new Headers(options.headers || {});
        if (options.body) headers.set('Content-Type', 'application/json');
        if (options.method && options.method !== 'GET') headers.set('X-CSRF-Token', csrf);
        const response = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
            ...options, headers, credentials: 'same-origin',
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.error || `Request failed (${response.status}).`);
        return data;
    }

    async function stopScanner() {
        if (scanner) {
            const active = scanner;
            scanner = null;
            await active.stop().catch(() => {});
        }
        document.querySelector('#checkin-reader').replaceChildren();
    }

    async function beginScan() {
        if (!window.Html5Qrcode) {
            message.textContent = 'The local QR scanner asset could not be loaded.';
            message.classList.add('error');
            return;
        }
        await stopScanner();
        busy = false;
        message.textContent = 'Point the camera at your personal QR code.';
        startButton.hidden = true;
        rescanButton.hidden = true;
        scanner = new Html5Qrcode('checkin-reader');
        try {
            await scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 240, height: 240 } }, async (text) => {
                if (busy) return;
                busy = true;
                const active = scanner;
                scanner = null;
                await active.stop().catch(() => {});
                try {
                    const result = await request('scan', { method: 'POST', body: JSON.stringify({ token: text.trim() }) });
                    const stamp = result.attendance.attended_at
                        ? new Date(result.attendance.attended_at.replace(' ', 'T')).toLocaleString()
                        : '';
                    message.textContent = result.duplicate
                        ? `${result.student.full_name} was already checked in today at ${stamp}.`
                        : `Welcome, ${result.student.full_name}. Your attendance was recorded at ${stamp}.`;
                    message.classList.remove('error');
                } catch (error) {
                    message.textContent = error.message;
                    message.classList.add('error');
                }
                rescanButton.hidden = false;
            }, () => {});
        } catch (error) {
            scanner = null;
            message.textContent = `Camera could not start: ${error.message}`;
            message.classList.add('error');
            startButton.hidden = false;
        }
    }

    startButton.addEventListener('click', beginScan);
    rescanButton.addEventListener('click', beginScan);
    window.addEventListener('pagehide', stopScanner);
    request('bootstrap').then((session) => {
        csrf = session.csrf;
        message.textContent = 'Camera access is used only to read your QR code.';
        startButton.disabled = false;
    }).catch((error) => {
        message.textContent = error.message;
        message.classList.add('error');
    });
})();
