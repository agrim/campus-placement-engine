import react from "@vitejs/plugin-react";
import { defineConfig } from "vitest/config";

export default defineConfig({
  base: "/campus-placement-engine/",
  plugins: [
    react(),
    {
      name: "static-website-security-policy",
      apply: "build",
      transformIndexHtml: () => [
        {
          tag: "meta",
          attrs: {
            "http-equiv": "Content-Security-Policy",
            content: [
              "default-src 'none'",
              "script-src 'self'",
              "style-src 'self'",
              // The demo sets only its progress bar's width through React.
              "style-src-attr 'unsafe-inline'",
              "img-src 'self'",
              "media-src 'self'",
              "base-uri 'none'",
              "form-action 'none'",
              "connect-src 'none'",
              "object-src 'none'",
              "frame-src 'none'",
              "worker-src 'none'",
            ].join("; "),
          },
          injectTo: "head-prepend",
        },
        {
          tag: "meta",
          attrs: { name: "referrer", content: "same-origin" },
          injectTo: "head-prepend",
        },
      ],
    },
  ],
  build: {
    sourcemap: false,
    target: "es2022",
  },
  test: {
    environment: "jsdom",
    setupFiles: "./src/test/setup.ts",
    css: true,
  },
});
