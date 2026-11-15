import { describe, expect, it } from 'vitest';
import locales from '../../resources/js/locales';

describe('locales del módulo', () => {
    it('trae el título en español', () => {
        expect(locales.es.newsletter.title).toBe('Boletín');
    });
});

describe('textos del formulario', () => {
    it('tienen las mismas claves en español y en inglés', () => {
        const keys = (value: unknown, prefix = ''): string[] =>
            typeof value === 'object' && value !== null
                ? Object.entries(value).flatMap(([key, inner]) => keys(inner, `${prefix}${key}.`))
                : [prefix];

        expect(keys(locales.en)).toEqual(keys(locales.es));
    });

    it('no llevan arrobas sin escapar, que rompen vue-i18n', () => {
        expect(JSON.stringify(locales)).not.toMatch(/@(?!'\})/);
    });
});
