import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';

const KEY = 'registry-bridge:view';

/**
 * The remembered cards/list choice for Discover and category pages, kept per
 * browser. Storage can be missing or throw (private windows); the choice then
 * lasts for the session only.
 */
export default class StoreViewService extends Service {
    @tracked view = StoreViewService.read();

    static read() {
        try {
            const value = window.localStorage.getItem(KEY);
            return value === 'list' ? 'list' : 'cards';
        } catch {
            return 'cards';
        }
    }

    set(view) {
        this.view = view === 'list' ? 'list' : 'cards';
        try {
            window.localStorage.setItem(KEY, this.view);
        } catch {
            // Not remembered past this session.
        }
    }
}
