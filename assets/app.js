import './stimulus_bootstrap.js';
import './styles/app.css';
import Alpine from 'alpinejs';
import QRCode from 'qrcode';

Alpine.data('gameForm', () => ({
    step: 'categories',
    guessGender: false,
    guessName: false,
    guessDate: false,
    guessWeight: false,
    guessHeight: false,
    nameMode: '',
    answerName: '',

    get stepSequence() {
        const s = ['categories'];
        if (this.guessDate) s.push('date');
        if (this.guessName) s.push('name');
        s.push('finish');
        return s;
    },

    get stepIndex() { return this.stepSequence.indexOf(this.step); },
    get totalSteps() { return this.stepSequence.length; },
    get stepNumber() { return this.stepIndex + 1; },
    get isFirst() { return this.stepIndex === 0; },
    get isLast() { return this.stepIndex === this.stepSequence.length - 1; },

    get hasCategory() {
        return this.guessGender || this.guessName || this.guessDate || this.guessWeight || this.guessHeight;
    },

    get canProceed() {
        if (this.step === 'categories') return this.hasCategory;
        if (this.step === 'name') {
            if (this.nameMode === '') return false;
            if (this.nameMode === 'hints') return this.answerName.trim() !== '';
            return true;
        }
        return true;
    },

    next() {
        if (!this.canProceed) return;
        if (!this.isLast) this.step = this.stepSequence[this.stepIndex + 1];
    },

    prev() {
        if (!this.isFirst) this.step = this.stepSequence[this.stepIndex - 1];
    },

    toggleCategory(cat) {
        this[cat] = !this[cat];
        if (cat === 'guessName' && !this.guessName) {
            this.nameMode = '';
            this.answerName = '';
        }
    },

    selectNameMode(mode) {
        this.nameMode = mode;
        if (mode !== 'hints') this.answerName = '';
    },
    // nameMode values: 'free' | 'hints'
}));

window.Alpine = Alpine;
Alpine.start();

// ── Page de partage ──────────────────────────────────────────────
const sharePage = document.getElementById('share-page');
if (sharePage) {
    const shareUrl   = sharePage.dataset.shareUrl;
    const shareTitle = sharePage.dataset.shareTitle;

    const mobileShare  = document.getElementById('mobile-share');
    const desktopShare = document.getElementById('desktop-share');
    const qrModal      = document.getElementById('qr-modal');
    const qrImage      = document.getElementById('qr-image');

    const openModal  = () => qrModal.classList.remove('hidden');
    const closeModal = () => qrModal.classList.add('hidden');

    if (navigator.share) {
        // Mobile : share sheet native
        mobileShare.classList.remove('hidden');
        document.getElementById('native-share-btn').addEventListener('click', async () => {
            try {
                await navigator.share({ title: shareTitle, url: shareUrl });
            } catch (_) {
                // annulation utilisateur, on ignore
            }
        });
    } else {
        // Desktop : boutons explicites + QR modal auto-ouvert
        desktopShare.classList.remove('hidden');
        desktopShare.style.display = 'flex';

        // Copier le lien
        const copyBtn   = document.getElementById('copy-link-btn');
        const copyLabel = document.getElementById('copy-label');
        copyBtn.addEventListener('click', async () => {
            await navigator.clipboard.writeText(shareUrl);
            copyLabel.textContent = copyBtn.dataset.copied;
            setTimeout(() => { copyLabel.textContent = copyBtn.dataset.copy; }, 2000);
        });

        // Générer le QR code
        QRCode.toDataURL(shareUrl, { width: 200, margin: 2, color: { dark: '#1a1a1a' } })
            .then(dataUrl => { qrImage.src = dataUrl; })
            .catch(() => {});

        // Auto-ouvrir la modal
        openModal();

        document.getElementById('qr-close').addEventListener('click', closeModal);
        document.getElementById('open-qr-btn').addEventListener('click', openModal);

        // Fermer en cliquant sur l'overlay
        qrModal.addEventListener('click', (e) => {
            if (e.target === qrModal) closeModal();
        });
    }
}
