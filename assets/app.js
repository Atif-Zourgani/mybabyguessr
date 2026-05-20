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
    const copyLabel  = document.getElementById('copy-label');
    const qrModal    = document.getElementById('qr-modal');
    const qrImage    = document.getElementById('qr-image');

    const openModal  = () => qrModal.classList.remove('hidden');
    const closeModal = () => qrModal.classList.add('hidden');

    // Bouton copier — clipboard API avec fallback select
    document.getElementById('copy-link-btn').addEventListener('click', () => {
        const input = document.getElementById('share-url-input');
        const originalLabel = sharePage.dataset.copyLabel;
        const copiedLabel   = sharePage.dataset.copiedLabel;

        const showCopied = () => {
            copyLabel.textContent = copiedLabel;
            setTimeout(() => { copyLabel.textContent = originalLabel; }, 2000);
        };

        if (navigator.clipboard) {
            navigator.clipboard.writeText(shareUrl).then(showCopied).catch(() => {
                input.select();
                document.execCommand('copy');
                showCopied();
            });
        } else {
            input.select();
            document.execCommand('copy');
            showCopied();
        }
    });

    // Si navigator.share disponible (mobile) : remplace les boutons desktop par le bouton natif
    if (navigator.share) {
        document.getElementById('desktop-share').style.display = 'none';
        const mobileShare = document.getElementById('mobile-share');
        mobileShare.classList.remove('hidden');
        document.getElementById('native-share-btn').addEventListener('click', async () => {
            try {
                await navigator.share({ title: shareTitle, url: shareUrl });
            } catch (_) {}
        });
    }

    // QR code — généré une fois, modal sur bouton
    QRCode.toDataURL(shareUrl, { width: 200, margin: 2, color: { dark: '#1a1a1a' } })
        .then(dataUrl => { qrImage.src = dataUrl; })
        .catch(() => {});

    document.getElementById('open-qr-btn').addEventListener('click', openModal);
    document.getElementById('qr-close').addEventListener('click', closeModal);
    qrModal.addEventListener('click', (e) => { if (e.target === qrModal) closeModal(); });
}
