import { render } from "@testing-library/react";
import axe from "axe-core";
import { describe, expect, it } from "vitest";
import App from "./App";

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

  it("offers a captioned real-product walkthrough", () => {
    const { getByLabelText } = render(<App />);
    const video = getByLabelText("Campus Placement Engine product walkthrough");
    expect(video.querySelector('source[type="video/webm"]')?.getAttribute("src"))
      .toMatch(/\/demo\/campus-placement-engine-demo\.webm$/);
    expect(video.querySelector('track[kind="captions"]')?.getAttribute("src"))
      .toMatch(/\/demo\/campus-placement-engine-demo\.vtt$/);
  });
});
