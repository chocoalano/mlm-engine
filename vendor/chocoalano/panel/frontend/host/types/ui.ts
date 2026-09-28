/**
 * The starter kit's shared UI types.
 *
 * `FlashToast` is the only one the panel reads: it is the shape the panel's
 * own `ShareFlashToast` middleware writes into the Inertia flash bag, so the
 * two have to agree and this records what the agreement is.
 */
export interface FlashToast {
    type: 'success' | 'error' | 'warning' | 'info';
    message: string;
    url?: string | null;
    urlLabel?: string | null;
}
