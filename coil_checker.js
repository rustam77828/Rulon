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

    const url = `check_coil.php?coil=${encodeURIComponent(coil)}&action=${action}`;

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'ok') {
                output.textContent = data.message;
                output.style.color = 'green';

                // Показываем дату и статус
                if (data.datetime) {
                    const statusText = action === 'complete' ? '✅ Set' : '⏳ Not finished';
                    timeOutput.textContent = `${statusText} • ${data.datetime}`;
                    timeOutput.style.color = 'green';
                }

                setTimeout(() => {
                    input.value = '';
                    input.focus();
                }, 700);

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