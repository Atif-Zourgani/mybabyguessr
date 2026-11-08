import './stimulus_bootstrap.js';
import './styles/app.css';
import Alpine from 'alpinejs';
import Focus from '@alpinejs/focus';
import heic2any from 'heic2any';

Alpine.plugin(Focus);

// ── Photo de couverture : réduction et redressement avant l'envoi ──
// Une photo de téléphone pèse souvent 5 à 10 Mo (refusée au-delà de 4 Mo) et peut être couchée selon son EXIF.
// On la réduit dans le navigateur ; le serveur refait de toute façon ses propres contrôles.
const IMAGE_MAX_BYTES = 4 * 1024 * 1024;
const IMAGE_MAX_DIMENSION = 1600; // le serveur affiche en 900 px : on garde de la marge

async function loadBitmap(file) {
    if (!window.createImageBitmap) return null;
    try {
        // 'from-image' applique l'orientation EXIF : l'image dessinée est dans le bon sens
        return await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch (_) {
        try { return await createImageBitmap(file); } catch (_) { return null; }
    }
}

async function prepareImage(file) {
    if (!HTMLCanvasElement.prototype.toBlob) return file;

    const bitmap = await loadBitmap(file);
    if (!bitmap) return file;

    try {
        const ratio = Math.min(1, IMAGE_MAX_DIMENSION / bitmap.width, IMAGE_MAX_DIMENSION / bitmap.height);
        // Déjà petite et légère : on garde l'original (le serveur gère l'orientation)
        if (ratio === 1 && file.size <= IMAGE_MAX_BYTES) return file;

        const width = Math.max(1, Math.round(bitmap.width * ratio));
        const height = Math.max(1, Math.round(bitmap.height * ratio));
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');
        context.fillStyle = '#fff'; // fond blanc sous les zones transparentes (PNG, WebP)
        context.fillRect(0, 0, width, height);
        context.drawImage(bitmap, 0, 0, width, height);

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
        if (!blob || (blob.size >= file.size && file.size <= IMAGE_MAX_BYTES)) return file;

        return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
    } catch (_) {
        return file;
    } finally {
        if (bitmap.close) bitmap.close();
    }
}

function replaceInputFile(input, file) {
    if (!window.DataTransfer) return;
    const transfer = new DataTransfer();
    transfer.items.add(file);
    input.files = transfer.files;
}

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
    imageConverting: false,

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

    async handleImageChange(event) {
        const file = event.target.files[0];
        this.imageError = null;
        this.imagePreview = null;
        this.imageConverting = false;
        if (!file) return;

        const isHeic = file.type === 'image/heic' || file.type === 'image/heif'
            || /\.(heic|heif)$/i.test(file.name);

        let processedFile = file;

        if (isHeic) {
            this.imageConverting = true;
            try {
                const blob = await heic2any({ blob: file, toType: 'image/jpeg', quality: 0.85 });
                const jpegBlob = Array.isArray(blob) ? blob[0] : blob;
                processedFile = new File(
                    [jpegBlob],
                    file.name.replace(/\.(heic|heif)$/i, '.jpg'),
                    { type: 'image/jpeg' }
                );
                replaceInputFile(event.target, processedFile);
            } catch (_) {
                this.imageConverting = false;
                this.imageError = 'type';
                event.target.value = '';
                return;
            }
            this.imageConverting = false;
        }

        const allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!allowed.includes(processedFile.type)) {
            this.imageError = 'type';
            event.target.value = '';
            return;
        }

        // Réduction et redressement : une photo de téléphone de 8 Mo n'est plus refusée
        this.imageConverting = true;
        const prepared = await prepareImage(processedFile);
        this.imageConverting = false;
        if (prepared !== processedFile) {
            processedFile = prepared;
            replaceInputFile(event.target, processedFile);
        }

        if (processedFile.size > IMAGE_MAX_BYTES) {
            this.imageError = 'size';
            event.target.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = (e) => { this.imagePreview = e.target.result; };
        reader.readAsDataURL(processedFile);
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
