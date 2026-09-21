function checkCoil(action) {
    const input = document.getElementById('coilInput');
    const output = document.getElementById('result');
    const timeOutput = document.getElementById('resultTime');

    const coil = input.value.trim();

    output.textContent = '';
    timeOutput.textContent = '';

    if (!coil) {
        output.textContent = 'Enter roll number';
        output.style.color = 'red';
        input.focus();
        return;
    }

    
    output.textContent = '⏳ Checking...';
    output.style.color = 'gray';

    
    fetch('check_coil.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: new URLSearchParams({
            coil: coil,
            action: action
        })
    })
        .then(res => {
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            return res.json();
        })
        .then(data => {
            if (data.status === 'ok') {
                output.textContent = data.message;
                output.style.color = 'green';

                
                if (data.datetime) {
                    const statusText = action === 'complete' ? '✅ Set' : '⏳ Not finished';
                    timeOutput.textContent = `${statusText} • ${data.datetime}`;
                    timeOutput.style.color = 'green';
                }

                setTimeout(() => {
                    input.value = '';
                    input.focus();
                }, 700);

                
                const historyPanel = document.getElementById('historyPanel');
                if (historyPanel && historyPanel.style.display !== 'none') {
                    loadWeek();
                }

            } else {
                output.textContent = data.message || 'Roll NOT found ❌';
                output.style.color = 'red';
                input.focus();
                input.select();
            }
        })
        .catch(err => {
            console.error(err);
            output.textContent = 'Server request error';
            output.style.color = 'red';
            input.focus();
        });
}



async function loadWeek() {
    const panel = document.getElementById('historyPanel');
    const rangeEl = document.getElementById('weekRange');
    const historyEl = document.getElementById('weekHistory');

    
    panel.style.display = 'block';
    historyEl.innerHTML = '⏳ Загрузка...';

    try {
        const response = await fetch('check_coil.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams({
                coil: 'week',
                action: 'week'
            })
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const data = await response.json();

        if (data.status !== 'ok') {
            historyEl.innerHTML = `❌ ${data.message || 'Ошибка загрузки'}`;
            return;
        }

        
        rangeEl.textContent = `${data.sunday} → ${data.friday}`;

        if (!data.logs || !data.logs.length) {
            historyEl.innerHTML = '<em>Нет записей за эту неделю</em>';
            return;
        }

        
        let html = '<table class="history-table">';
        html += '<tr><th>Coil</th><th>Action</th><th>Date</th><th>Time</th></tr>';

        data.logs.forEach(r => {
            const dt = r.timestamp ? r.timestamp.split(' ') : ['', ''];
            const date = dt[0] || '';
            const time = dt[1] ? dt[1].substring(0, 5) : '';
            const isComplete = r.action === 'complete';
            const actionText = isComplete ? '✅ Set' : '⏳ Partial';
            const actionClass = isComplete ? 'action-complete' : 'action-partial';

            html += `<tr>
                <td><b>${r.coil_number || '—'}</b></td>
                <td class="${actionClass}">${actionText}</td>
                <td>${date}</td>
                <td>${time}</td>
            </tr>`;
        });

        html += '</table>';
        historyEl.innerHTML = html;

    } catch (e) {
        console.error(e);
        historyEl.innerHTML = `❌ ${e.message}`;
    }
}
