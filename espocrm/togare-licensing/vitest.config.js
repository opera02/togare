import { defineConfig } from "vitest/config";

export default defineConfig({
  test: {
    environment: "jsdom",
    globals: true,
    include: ["tests/js/**/*.spec.js"],
  },
  resolve: {
    alias: {
      // EspoCRM expõe `view` como módulo global no runtime. Nos testes, usamos
      // um stub mínimo Backbone-compatível.
      view: new URL("./tests/js/__mocks__/view.js", import.meta.url).pathname,
    },
  },
});
