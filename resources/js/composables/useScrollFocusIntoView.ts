import { getCurrentInstance, onBeforeUnmount, onMounted } from 'vue';

const STICKY_HEADER_SELECTOR = '[data-sticky-header], header';

let references = 0;

function stickyHeader(): HTMLElement | null {
    const candidates = document.querySelectorAll<HTMLElement>(
        STICKY_HEADER_SELECTOR,
    );

    for (const candidate of candidates) {
        const { position } = window.getComputedStyle(candidate);

        if (position === 'sticky' || position === 'fixed') {
            return candidate;
        }
    }

    return null;
}

function intersects(a: DOMRect, b: DOMRect): boolean {
    return (
        a.top < b.bottom &&
        a.bottom > b.top &&
        a.left < b.right &&
        a.right > b.left
    );
}

function handleFocusIn(event: FocusEvent): void {
    const target = event.target;

    if (!(target instanceof HTMLElement)) {
        return;
    }

    const header = stickyHeader();

    if (!header || header.contains(target)) {
        return;
    }

    const headerRect = header.getBoundingClientRect();

    if (headerRect.height === 0 || headerRect.bottom <= 0) {
        return;
    }

    if (!intersects(target.getBoundingClientRect(), headerRect)) {
        return;
    }

    target.scrollIntoView({ block: 'center' });
}

function attach(): void {
    if (typeof document === 'undefined') {
        return;
    }

    references += 1;

    if (references === 1) {
        document.addEventListener('focusin', handleFocusIn);
    }
}

function detach(): void {
    if (typeof document === 'undefined' || references === 0) {
        return;
    }

    references -= 1;

    if (references === 0) {
        document.removeEventListener('focusin', handleFocusIn);
    }
}

export function useScrollFocusIntoView(): () => void {
    if (getCurrentInstance()) {
        onMounted(attach);
        onBeforeUnmount(detach);
    } else {
        attach();
    }

    return detach;
}
