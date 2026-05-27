import './stimulus_bootstrap.js';
import './styles/app.css';
import Alpine from 'alpinejs';

Alpine.data('gameForm', () => ({
    step: 'categories',
    guessGender: false,
    guessName: false,
    guessDate: false,
    guessWeight: false,
    guessHeight: false,
    nameMode: '',
    answerName: '',
    showCategoryError: false,
    showNameError: false,
    submitting: false,
    imagePreview: null,
    imageError: null,

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
        if (!this.canProceed) {
            if (this.step === 'categories') this.showCategoryError = true;
            if (this.step === 'name') this.showNameError = true;
            return;
        }
        this.showCategoryError = false;
        this.showNameError = false;
        if (!this.isLast) this.step = this.stepSequence[this.stepIndex + 1];
    },

    prev() {
        this.showCategoryError = false;
        this.showNameError = false;
        if (!this.isFirst) this.step = this.stepSequence[this.stepIndex - 1];
    },

    toggleCategory(cat) {
        this[cat] = !this[cat];
        this.showCategoryError = false;
        if (cat === 'guessName' && !this.guessName) {
            this.nameMode = '';
            this.answerName = '';
        }
    },

    selectNameMode(mode) {
        this.nameMode = mode;
        this.showNameError = false;
        if (mode !== 'hints') this.answerName = '';
    },

    handleImageChange(event) {
        const file = event.target.files[0];
        this.imageError = null;
        this.imagePreview = null;
        if (!file) return;
        const allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!allowed.includes(file.type)) {
            this.imageError = 'type';
            event.target.value = '';
            return;
        }
        if (file.size > 4 * 1024 * 1024) {
            this.imageError = 'size';
            event.target.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = (e) => { this.imagePreview = e.target.result; };
        reader.readAsDataURL(file);
    },
}));

window.Alpine = Alpine;
Alpine.start();

// ── Page de partage ──────────────────────────────────────────────
// turbo:load se déclenche à chaque navigation Turbo (et au chargement initial)
document.addEventListener('turbo:load', () => {
    const sharePage = document.getElementById('share-page');
    if (!sharePage) return;

    const shareUrl   = sharePage.dataset.shareUrl;
    const shareTitle = sharePage.dataset.shareTitle;
    const copyLabel  = document.getElementById('copy-label');

    document.getElementById('copy-link-btn').addEventListener('click', () => {
        const originalLabel = sharePage.dataset.copyLabel;
        const copiedLabel   = sharePage.dataset.copiedLabel;

        const showCopied = () => {
            copyLabel.textContent = copiedLabel;
            setTimeout(() => { copyLabel.textContent = originalLabel; }, 2000);
        };

        const fallback = () => {
            const ta = document.createElement('textarea');
            ta.value = shareUrl;
            ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            try { document.execCommand('copy'); showCopied(); } catch (_) {}
            document.body.removeChild(ta);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(shareUrl).then(showCopied).catch(fallback);
        } else {
            fallback();
        }
    });

    if (navigator.share) {
        document.getElementById('mobile-share').classList.remove('hidden');
        document.getElementById('native-share-btn').addEventListener('click', async () => {
            try {
                await navigator.share({ title: shareTitle, url: shareUrl });
            } catch (_) {}
        });
    }
});
