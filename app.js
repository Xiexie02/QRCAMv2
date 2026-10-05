(() => {
    'use strict';

    const state = { csrf: '', students: [], dashboard: null, scanner: null, qrStudent: null, reportRows: [] };
    const $ = (selector) => document.querySelector(selector);
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[char]);
    const initials = (name) => String(name || '?').trim().split(/\s+/).slice(0, 2).map((part) => part[0] || '').join('').toUpperCase();

    async function api(action, options = {}) {
        const headers = new Headers(options.headers || {});
        if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
        if (options.method && options.method !== 'GET' && state.csrf) headers.set('X-CSRF-Token', state.csrf);
        const response = await fetch(`api.php?action=${encodeURIComponent(action)}${options.query || ''}`, {
            ...options,
            headers,
            credentials: 'same-origin',
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.error || `Request failed (${response.status}).`);
        return payload;
    }

    function notice(message, isError = false) {
        const node = $('#notice');
        node.textContent = message;
        node.classList.toggle('error', isError);
        node.hidden = false;
        window.clearTimeout(notice.timer);
        notice.timer = window.setTimeout(() => { node.hidden = true; }, 6000);
    }

    function showAuthenticated(authenticated, adviser = '') {
        $('#login-view').hidden = authenticated;
        $('#app-view').hidden = !authenticated;
        if (authenticated) {
            $('#adviser-name').textContent = adviser || 'Adviser';
            refreshDashboard();
        }
    }

    async function init() {
        try {
            const session = await api('bootstrap');
            state.csrf = session.csrf;
            showAuthenticated(session.authenticated, session.adviser);
        } catch (error) {
            $('#login-view').hidden = false;
            $('#login-error').textContent = error.message;
        }
    }

    async function refreshDashboard() {
        if ($('#app-view').hidden) return;
        try {
            const data = await api('dashboard');
            state.dashboard = data;
            state.students = data.students;
            renderDashboard(data);
            if (!$('#students-screen').hidden) renderStudents(data.students);
            $('#header-date').textContent = new Date(`${data.date}T00:00:00`).toLocaleDateString(undefined, {
                weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
            });
        } catch (error) {
            notice(error.message, true);
        }
    }

    function renderDashboard(data) {
        $('#total-count').textContent = data.total;
        $('#present-count').textContent = data.present;
        $('#absent-count').textContent = data.absent;
        const presentRate = data.total ? Math.round(data.present / data.total * 100) : 0;
        const absentRate = data.total ? Math.round(data.absent / data.total * 100) : 0;
        $('#present-percent').textContent = `${presentRate}% of students`;
        $('#absent-percent').textContent = `${absentRate}% of students`;
        const students = Object.fromEntries(data.students.map((student) => [student.id, student]));
        $('#audit-rows').innerHTML = data.auditLogs.length ? data.auditLogs.map((log) => {
            const before = log.before_data || null;
            const after = log.after_data || {};
            const student = students[after.student_id];
            const statusChange = before
                ? `${esc(before.status || 'unrecorded')} → ${esc(after.status || 'unknown')}`
                : `set to ${esc(after.status || 'unknown')}`;
            const noteChange = before && before.note !== after.note
                ? ` · note: ${esc(before.note || '—')} → ${esc(after.note || '—')}`
                : (after.note ? ` · ${esc(after.note)}` : '');
            const change = `${esc(student?.full_name || after.student_id || '')} · ${esc(after.attendance_date || '')}: ${statusChange}${noteChange}`;
            const stamp = log.created_at ? new Date(String(log.created_at).replace(' ', 'T')).toLocaleString() : '';
            return `<tr><td>${esc(stamp)}</td><td>${esc(log.actor)}</td><td>${change}</td></tr>`;
        }).join('') : '<tr><td colspan="3" class="empty">No manual changes recorded.</td></tr>';
        const feed = data.attendance.slice().sort((a, b) => String(b.attended_at).localeCompare(String(a.attended_at)));
        $('#feed-rows').innerHTML = feed.length ? feed.map((entry) => {
            const student = students[entry.student_id];
            if (!student) return '';
            const time = entry.attended_at ? new Date(entry.attended_at.replace(' ', 'T')).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '—';
            return `<tr>
                <td><div class="student-cell"><span class="student-avatar">${esc(initials(student.full_name))}</span><span><strong>${esc(student.full_name)}</strong><small>${esc(student.student_no)}</small></span></div></td>
                <td>${esc(time)}</td><td><span class="status-badge ${esc(entry.status)}">${esc(entry.status)}</span></td>
                <td><button class="text-button adjust-attendance" data-student="${esc(student.id)}" data-date="${esc(data.date)}">Adjust</button></td>
            </tr>`;
        }).join('') : '<tr><td colspan="4" class="empty">No attendance recorded today.</td></tr>';
    }

    function renderStudents(students) {
        state.students = students;
        $('#roster-count').textContent = `${students.length} ${students.length === 1 ? 'student' : 'students'}`;
        const query = $('#student-search').value.trim().toLowerCase();
        const visible = students.filter((student) => `${student.full_name} ${student.student_no} ${student.class_section}`.toLowerCase().includes(query));
        $('#student-rows').innerHTML = visible.length ? visible.map((student) => `<tr>
            <td><div class="student-cell"><span class="student-avatar">${esc(initials(student.full_name))}</span><strong>${esc(student.full_name)}</strong></div></td>
            <td>${esc(student.student_no)}</td><td>${esc(student.class_section || '—')}</td>
            <td>${esc(String(student.created_at || '').slice(0, 10) || '—')}</td>
            <td><button class="text-button show-qr" data-student="${esc(student.id)}">View QR</button><button class="text-button adjust-attendance" data-student="${esc(student.id)}">Adjust</button></td>
        </tr>`).join('') : `<tr><td colspan="5" class="empty">${students.length ? 'No matching students.' : 'No students enrolled yet. Import a CSV roster to get started.'}</td></tr>`;
    }

    function setScreen(name) {
        const labels = { dashboard: 'Overview', students: 'Students', reports: 'Reports' };
        document.querySelectorAll('.screen').forEach((screen) => { screen.hidden = screen.id !== `${name}-screen`; });
        document.querySelectorAll('.nav-link').forEach((button) => button.classList.toggle('active', button.dataset.screen === name));
        $('#page-title').textContent = labels[name] || 'Overview';
        $('.sidebar').classList.remove('open');
        if (name === 'students') refreshDashboard();
    }

    async function loadStudents() {
        const result = await api('students');
        renderStudents(result.students);
    }

    async function startScanner() {
        const dialog = $('#scanner-dialog');
        const result = $('#scan-result');
        dialog.showModal();
        result.textContent = 'Starting camera…';
        if (!window.Html5Qrcode) {
            result.textContent = 'QR scanner library is missing. Run npm install in the project folder.';
            return;
        }
        try {
            state.scanner = new Html5Qrcode('reader');
            await state.scanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 240, height: 240 } },
                async (decodedText) => {
                    if (!state.scanner) return;
                    const scanner = state.scanner;
                    state.scanner = null;
                    await scanner.stop().catch(() => {});
                    try {
                        const payload = await api('scan', { method: 'POST', body: JSON.stringify({ token: decodedText.trim() }) });
                        const time = payload.attendance.attended_at ? new Date(payload.attendance.attended_at.replace(' ', 'T')).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '';
                        result.textContent = payload.duplicate
                            ? `${payload.student.full_name} is already marked present today (${time}).`
                            : `${payload.student.full_name} marked present at ${time}.`;
                        refreshDashboard();
                    } catch (error) {
                        result.textContent = error.message;
                    }
                },
                () => {},
            );
            result.textContent = 'Camera is ready. Hold the QR code in the frame.';
        } catch (error) {
            state.scanner = null;
            result.textContent = `Could not start camera: ${error.message}`;
        }
    }

    async function stopScanner() {
        if (state.scanner) {
            const scanner = state.scanner;
            state.scanner = null;
            await scanner.stop().catch(() => {});
        }
        const reader = $('#reader');
        reader.replaceChildren();
    }

    async function openQr(studentId) {
        const student = state.students.find((item) => item.id === studentId);
        if (!student) return;
        if (!window.QRCode) {
            notice('QR library is missing. Run npm install in the project folder.', true);
            return;
        }
        state.qrStudent = student;
        $('#qr-student-name').textContent = student.full_name;
        $('#qr-student-meta').textContent = `${student.student_no} · ${student.class_section || 'No section'}`;
        await QRCode.toCanvas($('#qr-canvas'), student.qr_token, {
            width: 280, margin: 2, errorCorrectionLevel: 'H', color: { dark: '#10251f', light: '#ffffff' },
        });
        $('#qr-dialog').showModal();
    }

    function openAttendance(studentId = '', date = '') {
        $('#attendance-student').innerHTML = state.students.map((student) =>
            `<option value="${esc(student.id)}">${esc(student.full_name)} · ${esc(student.student_no)}</option>`).join('');
        $('#attendance-student').value = studentId || state.students[0]?.id || '';
        $('#attendance-date').value = date || state.dashboard?.date || new Date().toISOString().slice(0, 10);
        $('#attendance-status').value = 'present';
        $('#attendance-note').value = '';
        $('#attendance-dialog').showModal();
    }

    async function importCsv(file) {
        if (!file) return;
        const form = new FormData();
        form.append('csv', file);
        try {
            const result = await api('import', { method: 'POST', body: form });
            const errors = result.errors.length ? ` ${result.errors.length} row issue(s): ${result.errors.slice(0, 3).join(' ')}` : '';
            notice(`Imported ${result.created}, skipped ${result.skipped}.${errors}`, result.errors.length > 0);
            await refreshDashboard();
            if (!$('#students-screen').hidden) await loadStudents();
        } catch (error) {
            notice(error.message, true);
        } finally {
            $('#csv-file').value = '';
        }
    }

    $('#login-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        $('#login-error').textContent = '';
        const loginForm = event.currentTarget;
        const form = new FormData(loginForm);
        try {
            const result = await api('login', {
                method: 'POST',
                body: JSON.stringify({ username: form.get('username'), password: form.get('password') }),
            });
            state.csrf = result.csrf;
            loginForm.reset();
            showAuthenticated(true, form.get('username'));
        } catch (error) {
            $('#login-error').textContent = error.message;
        }
    });

    $('#logout-button').addEventListener('click', async () => {
        try {
            await api('logout', { method: 'POST', body: '{}' });
            const session = await api('bootstrap');
            state.csrf = session.csrf;
            showAuthenticated(false);
        } catch (error) {
            notice(error.message, true);
        }
    });

    document.querySelectorAll('[data-screen]').forEach((button) => {
        button.addEventListener('click', async (event) => {
            event.preventDefault();
            const screen = button.dataset.screen;
            setScreen(screen);
            if (screen === 'students') await loadStudents().catch((error) => notice(error.message, true));
        });
    });
    $('#student-search').addEventListener('input', () => renderStudents(state.students));
    $('#open-scanner').addEventListener('click', startScanner);
    $('#scanner-stop').addEventListener('click', stopScanner);
    $('#scanner-dialog').addEventListener('close', stopScanner);
    $('#quick-import').addEventListener('click', () => $('#csv-file').click());
    $('#students-import').addEventListener('click', () => $('#csv-file').click());
    $('#csv-file').addEventListener('change', (event) => importCsv(event.target.files[0]));
    document.body.addEventListener('click', (event) => {
        const qrButton = event.target.closest('.show-qr');
        if (qrButton) openQr(qrButton.dataset.student).catch((error) => notice(error.message, true));
        const adjustButton = event.target.closest('.adjust-attendance');
        if (adjustButton) openAttendance(adjustButton.dataset.student, adjustButton.dataset.date || '');
        const closeButton = event.target.closest('.close-dialog');
        if (closeButton) closeButton.closest('dialog').close();
    });
    $('#attendance-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            await api('set_attendance', {
                method: 'POST',
                body: JSON.stringify({
                    student_id: $('#attendance-student').value,
                    date: $('#attendance-date').value,
                    status: $('#attendance-status').value,
                    note: $('#attendance-note').value,
                }),
            });
            $('#attendance-dialog').close();
            notice('Attendance updated and audit entry recorded.');
            await refreshDashboard();
        } catch (error) {
            notice(error.message, true);
        }
    });
    $('#report-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const start = $('#report-start').value;
        const end = $('#report-end').value;
        try {
            const result = await api('report', { query: `&start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}` });
            state.reportRows = result.rows;
            $('#report-title').textContent = `Attendance: ${start} through ${end} · ${result.rows.length} student-days`;
            $('#report-rows').innerHTML = result.rows.length ? result.rows.map((row) => `<tr>
                <td>${esc(row.date)}</td><td>${esc(row.student_no)}</td><td>${esc(row.full_name)}</td><td>${esc(row.class_section || '—')}</td>
                <td><span class="status-badge ${esc(row.status)}">${esc(row.status)}</span></td><td>${esc(row.attended_at || '—')}</td><td>${esc(row.note || '—')}</td>
                <td><button class="text-button adjust-attendance" data-student="${esc(state.students.find((s) => s.student_no === row.student_no)?.id || '')}" data-date="${esc(row.date)}">Adjust</button></td>
            </tr>`).join('') : '<tr><td colspan="8" class="empty">No students are enrolled.</td></tr>';
            $('#print-report').disabled = false;
        } catch (error) {
            notice(error.message, true);
        }
    });
    $('#print-report').addEventListener('click', () => window.print());
    $('#download-qr').addEventListener('click', () => {
        if (!state.qrStudent) return;
        const link = document.createElement('a');
        link.download = `QRCAM-${state.qrStudent.student_no}.png`;
        link.href = $('#qr-canvas').toDataURL('image/png');
        link.click();
    });
    $('#print-qr').addEventListener('click', () => {
        const student = state.qrStudent;
        if (!student) return;
        const image = $('#qr-canvas').toDataURL('image/png');
        const printWindow = window.open('', '_blank', 'noopener');
        if (!printWindow) {
            notice('Allow pop-ups to print this QR code.', true);
            return;
        }
        printWindow.document.write(`<!doctype html><title>Student QR</title><style>body{font:16px Arial;text-align:center;padding:30px}img{width:280px;height:280px}</style><h1>${esc(student.full_name)}</h1><p>${esc(student.student_no)} · ${esc(student.class_section)}</p><img src="${image}" onload="window.print()"><p>QRCAMv2 attendance code</p>`);
        printWindow.document.close();
    });
    $('#menu-toggle').addEventListener('click', () => $('.sidebar').classList.toggle('open'));
    document.querySelectorAll('.modal').forEach((dialog) => dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    }));

    init();
    window.setInterval(refreshDashboard, 3000);
})();
