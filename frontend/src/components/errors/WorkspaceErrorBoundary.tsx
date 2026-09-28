import { Component, type ErrorInfo, type ReactNode } from 'react';

type Props = {
  children: ReactNode;
  onDashboard(): void;
};

type State = {
  error: Error | null;
};

export class WorkspaceErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('Workspace module render failed', error, info);
  }

  private retry = (): void => {
    this.setState({ error: null });
  };

  render(): ReactNode {
    if (!this.state.error) return this.props.children;

    return (
      <main className="management-page workspace-error-page">
        <section className="panel workspace-error-panel" role="alert">
          <span className="eyebrow">MODULE RECOVERY</span>
          <h1>This module could not be rendered</h1>
          <p>
            The rest of the workspace is still available. Retry this screen, return to the dashboard,
            or reload the application if local assets changed after a build.
          </p>
          <div className="inline-actions">
            <button type="button" className="primary-button" onClick={this.retry}>
              Try again
            </button>
            <button
              type="button"
              className="secondary-button"
              onClick={() => {
                this.setState({ error: null });
                this.props.onDashboard();
              }}
            >
              Dashboard
            </button>
            <button type="button" className="secondary-button" onClick={() => window.location.reload()}>
              Reload application
            </button>
          </div>
          {import.meta.env.DEV && (
            <small className="workspace-error-hint">
              Development detail: {this.state.error.message || this.state.error.name}
            </small>
          )}
        </section>
      </main>
    );
  }
}
