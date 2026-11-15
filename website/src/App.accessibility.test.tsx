import { render } from "@testing-library/react";
import axe from "axe-core";
import { describe, expect, it } from "vitest";
import App from "./App";
import styles from "./styles.css?raw";

describe("website accessibility", () => {
  it("has no axe violations in the initial marketing and demo view", async () => {
    const { container } = render(<App />);
    const results = await axe.run(container);
    const violations = results.violations.map((violation) => ({
      id: violation.id,
      impact: violation.impact,
      help: violation.help,
      targets: violation.nodes.map((node) => node.target),
    }));

    expect(violations).toEqual([]);
  });

  it("offers a privacy-safe public pilot path", () => {
    const { getAllByRole, getByText } = render(<App />);
    const pilotLinks = getAllByRole("link", { name: /discuss a university pilot|discuss a pilot/i });
    expect(pilotLinks.some((link) => link.getAttribute("href")?.includes("/discussions/new?category=general"))).toBe(true);
    expect(getByText(/do not include candidate details/i)).toBeInTheDocument();
  });

  it("reserves space and defers the below-fold walkthrough screenshot", () => {
    const { getByAltText } = render(<App />);
    const screenshot = getByAltText("The live placement board reflowed for a phone-width screen");
    expect(screenshot).toHaveAttribute("width", "390");
    expect(screenshot).toHaveAttribute("height", "844");
    expect(screenshot).toHaveAttribute("loading", "lazy");
    expect(screenshot).not.toHaveAttribute("fetchpriority");
  });

  it("offers a captioned real-product walkthrough", () => {
    const { getByLabelText } = render(<App />);
    const video = getByLabelText("Campus Placement Engine product walkthrough");
    expect(video).toHaveAttribute("width", "1440");
    expect(video).toHaveAttribute("height", "900");
    expect(video).toHaveAttribute("preload", "metadata");
    expect(video).toHaveAttribute("loading", "lazy");
    expect(video).toHaveAttribute("controls");
    expect(video.querySelector('source[type="video/webm"]')?.getAttribute("src"))
      .toMatch(/\/demo\/campus-placement-engine-demo\.webm$/);
    expect(video.querySelector('track[kind="captions"]')?.getAttribute("src"))
      .toMatch(/\/demo\/campus-placement-engine-demo\.vtt$/);
  });

  it("keeps a fallback glyph while silencing decorative generated bullets", () => {
    const rule = styles.match(/\.product-tour-copy li::before\s*\{([^}]+)\}/)?.[1];
    expect(rule).toMatch(/content:\s*"•";\s*content:\s*"•"\s*\/\s*"";/);
  });
});
