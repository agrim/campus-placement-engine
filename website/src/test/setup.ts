import "@testing-library/jest-dom/vitest";
import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";

// axe checks canvas support while evaluating contrast. jsdom intentionally
// leaves it unimplemented; returning null matches an unavailable canvas API
// without emitting a misleading test warning.
Object.defineProperty(HTMLCanvasElement.prototype, "getContext", {
  configurable: true,
  value: () => null,
});

afterEach(() => {
  cleanup();
});
