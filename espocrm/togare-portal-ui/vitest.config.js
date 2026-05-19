import { defineConfig } from "vitest/config";

export default defineConfig({
  test: {
    environment: "jsdom",
    globals: true,
    include: ["tests/js/**/*.spec.js"],
  },
  resolve: {
    alias: {
      // Login stock do EspoCRM — stub mínimo (PortalSplash o estende).
      "views/login": new URL(
        "./tests/js/__mocks__/login-view.js",
        import.meta.url,
      ).pathname,

      // Settings record edit — stub mínimo (painel admin o estende).
      "views/settings/record/edit": new URL(
        "./tests/js/__mocks__/settings-edit.js",
        import.meta.url,
      ).pathname,

      // Helper puro de contraste resolvido pelo arquivo real (sem mock).
      "togare-portal-ui:helpers/contrast": new URL(
        "./src/files/client/custom/modules/togare-portal-ui/src/helpers/contrast.js",
        import.meta.url,
      ).pathname,

      // Views custom resolvidas pelos arquivos reais.
      "togare-portal-ui:views/portal/login": new URL(
        "./src/files/client/custom/modules/togare-portal-ui/src/views/portal/login.js",
        import.meta.url,
      ).pathname,
      "togare-portal-ui:views/admin/portal-appearance": new URL(
        "./src/files/client/custom/modules/togare-portal-ui/src/views/admin/portal-appearance.js",
        import.meta.url,
      ).pathname,
    },
  },
});
