function updateStatus(select, id) {
    if (select.value === 'done') {
        let form = document.createElement('form');
        form.method = 'POST';
        form.action = 'index.php?action=update_status';
        form.innerHTML = `<input type="hidden" name="id" value="${id}">
                          <input type="hidden" name="status" value="done">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function updateKeterangan(select, id) {
    let form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php?action=update_keterangan';
    form.innerHTML = `<input type="hidden" name="id" value="${id}">
                      <input type="hidden" name="keterangan" value="${select.value}">`;
    document.body.appendChild(form);
    form.submit();
}

function popupHapusUser(id) {
    if (confirm('Hapus user ini?')) {
        let form = document.createElement('form');
        form.method = 'POST';
        form.action = 'index.php?action=hapus_user_bilik';
        form.innerHTML = `<input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function pindahKeBilik(id) {
    if (confirm('Pindahkan ke bilik?')) {
        window.location = `index.php?action=pindah_dari_waiting&id=${id}`;
    }
}

function hapusBilik(id) {
    if (confirm('Hapus bilik ini?')) {
        window.location = `index.php?action=hapus_bilik&id=${id}`;
    }
}

function perpanjang(id_user, id_bilik) {
    window.location = `index.php?action=perpanjang&id_user=${id_user}&id_bilik=${id_bilik}`;
}

// Countdown untuk tabel bilik
document.querySelectorAll('.countdown').forEach(span => {
    let out = parseInt(span.dataset.out);
    function updateCountdown() {
        let now = new Date().getTime();
        let sisa = out - now;
        let row = span.parentElement.parentElement;
        if (sisa < 0) {
            span.textContent = '00:00:00';
            row.classList.remove('bg-yellow-500', 'bg-white');
            row.classList.add('bg-red-500');
        } else {
            let jam = Math.floor(sisa / 3600000);
            let menit = Math.floor((sisa % 3600000) / 60000);
            let detik = Math.floor((sisa % 60000) / 1000);
            span.textContent = `${jam.toString().padStart(2,'0')}:${menit.toString().padStart(2,'0')}:${detik.toString().padStart(2,'0')}`;
            row.classList.remove('bg-red-500');
            if (sisa <= 600000) {
                row.classList.remove('bg-white');
                row.classList.add('bg-yellow-500');
            } else {
                row.classList.remove('bg-yellow-500');
                row.classList.add('bg-white');
            }
        }
    }
    updateCountdown();
    setInterval(updateCountdown, 1000);
});

// Countdown untuk waiting list (Estimasi Kosong dan Selesai)
function updateWaitingCountdown() {
    document.querySelectorAll('#waiting-table tbody tr').forEach((row, index) => {
        let id = row.dataset.id;
        let idBilik = row.dataset.idBilik;
        let waktuMasuk = parseInt(row.dataset.waktuMasuk) * 1000;
        let keterangan = waitingData.find(w => w.id == id).keterangan;
        
        if (keterangan === 'done') {
            row.querySelector('.estimasi-kosong').textContent = '0';
            row.querySelector('.estimasi-selesai').textContent = '';
            row.classList.add('bg-green-500');
            return;
        }

        // Hitung posisi dalam waiting list untuk bilik yang sama
        let posisi = 0;
        for (let i = 0; i < waitingData.length; i++) {
            if (waitingData[i].id_bilik == idBilik && waitingData[i].id <= id) {
                posisi++;
            }
        }

        // Ambil sisa waktu user di bilik
        let sisaTimes = (bilikUsers[idBilik] || []).map(t => t * 1000 - new Date().getTime()).filter(t => t > 0);
        sisaTimes.sort((a, b) => a - b);
        let estimasiKosong = sisaTimes[posisi - 1] || 0;
        
        // Update Estimasi Kosong
        let estimasiKosongStr = estimasiKosong > 0 ? new Date(estimasiKosong).toISOString().substr(11, 8) : '00:00:00';
        row.querySelector('.estimasi-kosong').textContent = estimasiKosongStr;

        // Update Estimasi Selesai
        let estimasiMasuk = waktuMasuk + estimasiKosong;
        let estimasiSelesai = estimasiMasuk + 7200000; // +2 jam
        let estimasiSelesaiStr = new Date(estimasiSelesai).toISOString().substr(11, 5);
        row.querySelector('.estimasi-selesai').textContent = estimasiSelesaiStr;
    });
}

// Jalankan dan update setiap detik
updateWaitingCountdown();
setInterval(updateWaitingCountdown, 1000);