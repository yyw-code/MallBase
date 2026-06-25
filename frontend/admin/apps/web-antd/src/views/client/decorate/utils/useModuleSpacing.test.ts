import type { ModuleItem } from './useModuleSpacing';

import { describe, expect, it } from 'vitest';

import {
  getModuleMarginOverall,
  getModuleMarginSide,
  getModulePaddingOverall,
  getModulePaddingSide,
  syncModulePaddingCompat,
  updateModuleMarginAll,
  updateModuleMarginSide,
  updateModulePaddingAll,
  updateModulePaddingSide,
} from './useModuleSpacing';

describe('useModuleSpacing', () => {
  it('updates all padding sides from one value', () => {
    const module: ModuleItem = { config: { paddingX: 10, paddingY: 4 } };

    updateModulePaddingAll(module, 28);

    expect(module.config).toMatchObject({
      padding: 28,
      paddingBottom: 28,
      paddingLeft: 28,
      paddingRight: 28,
      paddingTop: 28,
      paddingX: 28,
      paddingY: 28,
      padding_bottom: 28,
      padding_left: 28,
      padding_right: 28,
      padding_top: 28,
      padding_x: 28,
      padding_y: 28,
    });
    expect(getModulePaddingOverall(module)).toBe(28);
  });

  it('keeps right and left padding independent after side updates', () => {
    const module: ModuleItem = { config: { paddingX: 28, paddingY: 28 } };

    updateModulePaddingSide(module, 'paddingRight', 80);

    expect(getModulePaddingSide(module, 'paddingLeft')).toBe(28);
    expect(getModulePaddingSide(module, 'paddingRight')).toBe(80);
    expect(module.config.paddingLeft).toBe(28);
    expect(module.config.paddingRight).toBe(80);

    updateModulePaddingSide(module, 'paddingLeft', 10);

    expect(getModulePaddingSide(module, 'paddingLeft')).toBe(10);
    expect(getModulePaddingSide(module, 'paddingRight')).toBe(80);
    expect(module.config.paddingLeft).toBe(10);
    expect(module.config.paddingRight).toBe(80);
    expect(module.config.paddingX).toBe(45);
  });

  it('uses four-side fields before legacy paddingX and paddingY', () => {
    const module: ModuleItem = {
      config: {
        paddingBottom: 12,
        paddingLeft: 10,
        paddingRight: 80,
        paddingTop: 20,
        paddingX: 28,
        paddingY: 28,
      },
    };

    syncModulePaddingCompat(module.config);

    expect(getModulePaddingSide(module, 'paddingTop')).toBe(20);
    expect(getModulePaddingSide(module, 'paddingRight')).toBe(80);
    expect(getModulePaddingSide(module, 'paddingBottom')).toBe(12);
    expect(getModulePaddingSide(module, 'paddingLeft')).toBe(10);
    expect(module.config.paddingX).toBe(45);
    expect(module.config.paddingY).toBe(16);
  });

  it('keeps home module padding compatible with legacy padding aliases', () => {
    const module: ModuleItem = {
      config: {
        paddingBottom: 0,
        paddingLeft: 28,
        paddingRight: 28,
        paddingTop: 12,
        paddingX: 20,
        paddingY: 4,
      },
    };

    syncModulePaddingCompat(module.config);

    expect(getModulePaddingSide(module, 'paddingTop')).toBe(12);
    expect(getModulePaddingSide(module, 'paddingRight')).toBe(28);
    expect(getModulePaddingSide(module, 'paddingBottom')).toBe(0);
    expect(getModulePaddingSide(module, 'paddingLeft')).toBe(28);
    expect(module.config.paddingX).toBe(28);
    expect(module.config.paddingY).toBe(6);
  });

  it('updates all margin sides from one value', () => {
    const module: ModuleItem = { config: { marginBottom: 8, marginTop: 4 } };

    updateModuleMarginAll(module, 16);

    expect(module.config).toMatchObject({
      marginBottom: 16,
      marginLeft: 16,
      marginRight: 16,
      marginTop: 16,
      margin_bottom: 16,
      margin_left: 16,
      margin_right: 16,
      margin_top: 16,
    });
    expect(getModuleMarginOverall(module)).toBe(16);
  });

  it('keeps margin sides independent after side updates', () => {
    const module: ModuleItem = { config: { marginLeft: 4, marginRight: 8 } };

    updateModuleMarginSide(module, 'marginLeft', 20);
    updateModuleMarginSide(module, 'marginRight', 0);

    expect(getModuleMarginSide(module, 'marginLeft')).toBe(20);
    expect(getModuleMarginSide(module, 'marginRight')).toBe(0);
    expect(module.config.margin_left).toBe(20);
    expect(module.config.margin_right).toBe(0);
    expect(getModuleMarginOverall(module)).toBe(5);
  });
});
