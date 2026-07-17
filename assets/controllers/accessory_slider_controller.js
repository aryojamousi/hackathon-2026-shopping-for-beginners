import { Controller } from '@hotwired/stimulus';

/*
 * Accessory slider — a simple horizontal carousel.
 *
 * Scrolls the track one "page" (its visible width) at a time via the prev/next
 * arrows, and renders clickable pagination dots that stay in sync with the
 * current scroll position.
 */
export default class extends Controller {
    static targets = ['track', 'dots'];

    connect() {
        this.onScroll = this.updateDots.bind(this);
        this.trackTarget.addEventListener('scroll', this.onScroll, { passive: true });

        // Rebuild dots whenever the track changes size. This covers the initial
        // reveal (the slider lives inside a hidden results panel, so it has zero
        // width at connect time) as well as viewport resizes that change how many
        // cards fit per page.
        this.resizeObserver = new ResizeObserver(() => this.buildDots());
        this.resizeObserver.observe(this.trackTarget);

        this.buildDots();
    }

    disconnect() {
        this.trackTarget.removeEventListener('scroll', this.onScroll);
        if (this.resizeObserver) {
            this.resizeObserver.disconnect();
        }
    }

    get pageCount() {
        const { scrollWidth, clientWidth } = this.trackTarget;
        if (clientWidth === 0) return 1;
        return Math.max(1, Math.ceil(scrollWidth / clientWidth));
    }

    get currentPage() {
        const { scrollLeft, clientWidth } = this.trackTarget;
        if (clientWidth === 0) return 0;
        return Math.round(scrollLeft / clientWidth);
    }

    prev() {
        this.trackTarget.scrollBy({ left: -this.trackTarget.clientWidth, behavior: 'smooth' });
    }

    next() {
        this.trackTarget.scrollBy({ left: this.trackTarget.clientWidth, behavior: 'smooth' });
    }

    goTo(event) {
        const page = Number(event.currentTarget.dataset.page);
        this.trackTarget.scrollTo({ left: page * this.trackTarget.clientWidth, behavior: 'smooth' });
    }

    buildDots() {
        if (!this.hasDotsTarget) return;
        const pages = this.pageCount;
        this.dotsTarget.innerHTML = '';
        for (let i = 0; i < pages; i += 1) {
            const dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'tm-slider__dot';
            dot.dataset.page = String(i);
            dot.dataset.action = 'accessory-slider#goTo';
            this.dotsTarget.appendChild(dot);
        }
        this.updateDots();
    }

    updateDots() {
        if (!this.hasDotsTarget) return;
        const current = this.currentPage;
        Array.from(this.dotsTarget.children).forEach((dot, i) => {
            dot.classList.toggle('tm-slider__dot--active', i === current);
        });
    }
}
