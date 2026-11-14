import { describe, expect, it } from 'vitest';
import locales from '../../resources/js/locales';

describe('locales del módulo', () => {
    it('trae el título en español', () => {
        expect(locales.es.newsletter.title).toBe('Boletín');
    });
});
