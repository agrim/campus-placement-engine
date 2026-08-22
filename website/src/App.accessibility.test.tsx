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
});
