import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import { DemoApp } from "./DemoApp";
import { guidedSteps, roleConfig } from "./scenario";

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

describe("DemoApp", () => {
  it("preserves the shared scenario when the visitor changes profiles", async () => {
    const user = userEvent.setup();
    render(<DemoApp />);

    await user.click(screen.getByRole("button", {
      name: new RegExp(escapeRegExp(guidedSteps[0].actionLabel), "i"),
    }));
    expect(screen.getByText("Both interviews protected")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /^Mobile tracker/i }));

    expect(screen.getByRole("button", { name: /^Mobile tracker/i })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("button", {
      name: new RegExp(escapeRegExp(guidedSteps[1].actionLabel), "i"),
    })).toBeInTheDocument();
    const opportunities = screen.getByLabelText("Dev Malhotra opportunities visible to this role");
    expect(within(opportunities).getByText("11:50")).toBeInTheDocument();
    expect(screen.getByText("Both interviews protected")).toBeInTheDocument();
  });

  it("masks private fields, company scope, and cross-company activity", async () => {
    const user = userEvent.setup();
    render(<DemoApp />);

    expect(screen.getByText("Priority outreach")).toBeInTheDocument();
    expect(screen.getByText("Allow 10 minutes for lift transfer")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /^Atlas Systems tracker/i }));

    let opportunities = screen.getByLabelText("Dev Malhotra opportunities visible to this role");
    expect(within(opportunities).getByText("Atlas Systems")).toBeInTheDocument();
    expect(within(opportunities).queryByText("Nova Labs")).not.toBeInTheDocument();
    expect(screen.queryByText("Priority outreach")).not.toBeInTheDocument();
    expect(screen.queryByText("Allow 10 minutes for lift transfer")).not.toBeInTheDocument();
    const activity = screen.getByRole("region", { name: "Activity trail" });
    expect(activity).not.toHaveTextContent("Nova Labs");

    await user.click(screen.getByRole("button", { name: "Nova Labs" }));
    opportunities = screen.getByLabelText("Dev Malhotra opportunities visible to this role");
    expect(within(opportunities).getByText("Nova Labs")).toBeInTheDocument();
    expect(within(opportunities).queryByText("Atlas Systems")).not.toBeInTheDocument();
    expect(activity).not.toHaveTextContent("Atlas Systems");

    await user.click(screen.getByRole("button", { name: /^Mobile tracker/i }));
    expect(screen.getByText("Allow 10 minutes for lift transfer")).toBeInTheDocument();
    expect(screen.queryByText("Priority outreach")).not.toBeInTheDocument();
  });

  it("completes the guided mission through every required profile", async () => {
    const user = userEvent.setup();
    render(<DemoApp />);

    for (const step of guidedSteps) {
      if (step.id === "review-trace") {
        expect(document.querySelector(".outcome-summary")).toBeNull();
      }
      const actionName = new RegExp(escapeRegExp(step.actionLabel), "i");
      let action = screen.queryByRole("button", { name: actionName });

      if (!action) {
        const requiredRole = step.role === "company" && step.companyScope
          ? `${step.companyScope === "atlas" ? "Atlas Systems" : "Nova Labs"} tracker`
          : roleConfig[step.role].label;
        await user.click(screen.getByRole("button", {
          name: new RegExp(`Continue as ${escapeRegExp(requiredRole)}`, "i"),
        }));
        action = screen.getByRole("button", { name: actionName });
      }

      await user.click(action);

      if (step.id === "record-placement") {
        expect(screen.queryByRole("heading", { name: "Dev attended both interviews and chose Nova Labs." })).not.toBeInTheDocument();
      }
    }

    const outcome = document.querySelector<HTMLElement>(".outcome-summary");
    expect(outcome).not.toBeNull();
    expect(outcome).toHaveTextContent(/2\s*Interviews attended/i);
    expect(outcome).toHaveTextContent(/1\s*Placement recorded/i);
    expect(screen.getAllByText("Mission complete").length).toBeGreaterThan(0);
    const opportunities = screen.getByLabelText("Dev Malhotra opportunities visible to this role");
    expect(within(opportunities).getByText("Placed")).toBeInTheDocument();
    expect(within(opportunities).getByText("Opportunity closed")).toBeInTheDocument();
    expect(screen.getByText(/Closed Dev’s competing Atlas application/i)).toBeInTheDocument();
  });
});
