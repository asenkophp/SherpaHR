import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Api } from './api';
import { AttentionItem, AttentionSummary, Comment, Context, Project, Task, TaskDetail, TaskStatus } from './models';

type View = 'attention' | 'project';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './app.html',
})
export class AppComponent {
  api = inject(Api);
  context = signal<Context | null>(null);
  projects = signal<Project[]>([]);
  project = signal<Project | null>(null);
  tasks = signal<Task[]>([]);
  detail = signal<TaskDetail | null>(null);
  loading = signal(false);
  saving = signal(false);
  error = signal('');
  notice = signal('');
  status = '';
  search = '';
  comment = '';
  creating = signal(false);
  draft = this.emptyDraft();
  /** null until the first load decides: leads start on the attention view. */
  view = signal<View | null>(null);
  attention = signal<AttentionSummary | null>(null);
  attentionLoading = signal(false);
  attentionError = signal('');
  attentionCount = computed(() => this.attention()?.items.length ?? 0);
  private generation = 0;
  private attentionGeneration = 0;
  private detailGeneration = 0;
  readonly statuses: { value: TaskStatus; label: string }[] = [
    { value: 'open', label: 'Открыта' }, { value: 'in_progress', label: 'В работе' },
    { value: 'blocked', label: 'Заблокирована' }, { value: 'done', label: 'Готово' },
  ];

  constructor() { void this.load(); }

  async load(): Promise<void> {
    const generation = ++this.generation;
    this.loading.set(true);
    this.error.set('');
    this.closeDetail();
    try {
      const [context, projects] = await Promise.all([
        this.api.request<Context>('/context'), this.api.request<Project[]>('/projects'),
      ]);
      if (generation !== this.generation) return;
      this.context.set(context);
      this.projects.set(projects);
      const summary = await this.refreshAttention();
      if (generation !== this.generation) return;
      const leadsProjects = (summary?.projects.length ?? 0) > 0;
      if (this.view() === 'attention' || (this.view() === null && leadsProjects)) {
        this.showAttention();
        return;
      }
      const project = projects.find(p => p.id === this.project()?.id) ?? projects[0];
      if (project) await this.selectProject(project);
    } catch (error) {
      if (generation === this.generation) this.fail(error);
    } finally {
      if (generation === this.generation) this.loading.set(false);
    }
  }

  switchUser(value: string): void {
    this.api.userId = Number(value);
    this.view.set(null);
    this.attention.set(null);
    this.attentionError.set('');
    this.project.set(null);
    this.projects.set([]);
    this.tasks.set([]);
    this.context.set(null);
    this.notice.set('');
    void this.load();
  }

  async selectProject(project: Project): Promise<void> {
    const generation = ++this.generation;
    this.view.set('project');
    this.loading.set(true);
    this.error.set('');
    this.notice.set('');
    this.project.set(project);
    this.tasks.set([]);
    this.status = '';
    this.search = '';
    this.closeDetail();
    try {
      const tasks = await this.api.request<Task[]>(`/projects/${project.id}/tasks`);
      if (generation === this.generation) this.tasks.set(tasks);
    } catch (error) {
      if (generation === this.generation) this.fail(error);
    } finally {
      if (generation === this.generation) this.loading.set(false);
    }
  }

  /** Sidebar entry: show the summary and fetch a fresh one. */
  openAttention(): void {
    this.showAttention();
    void this.refreshAttention();
  }

  showAttention(): void {
    ++this.generation; // a pending project load must not switch the view back
    this.view.set('attention');
    this.loading.set(false);
    this.error.set('');
    this.notice.set('');
    this.closeDetail();
  }

  async refreshAttention(): Promise<AttentionSummary | null> {
    const generation = ++this.attentionGeneration;
    const userId = this.api.userId;
    this.attentionLoading.set(true);
    this.attentionError.set('');
    try {
      const summary = await this.api.request<AttentionSummary>('/attention');
      if (generation !== this.attentionGeneration || userId !== this.api.userId) return null;
      this.attention.set(summary);
      return summary;
    } catch (error) {
      if (generation === this.attentionGeneration) {
        this.attentionError.set(error instanceof Error ? error.message : 'Не удалось загрузить сводку');
      }
      return null;
    } finally {
      if (generation === this.attentionGeneration) this.attentionLoading.set(false);
    }
  }

  async openAttentionItem(item: AttentionItem): Promise<void> {
    const project = this.projects().find(p => p.id === item.project.id);
    if (!project) return;
    // The task card takes members and the archive flag from the current project.
    this.project.set(project);
    await this.openTask(item.task);
    if (this.detail()?.id === item.task.id && matchMedia('(max-width: 850px)').matches) {
      // On narrow screens the card is rendered below the list.
      setTimeout(() => document.querySelector('.detail')?.scrollIntoView({ block: 'start' }));
    }
  }

  attentionHint(): string {
    const summary = this.attention();
    if (!summary) return this.attentionError() ? 'Сводка недоступна' : 'Загружаем…';
    if (!summary.projects.length) return 'Вы не руководите проектами';
    const count = summary.items.length;
    return count ? `${count} ${this.plural(count, 'задача', 'задачи', 'задач')}` : 'Всё спокойно';
  }

  filteredTasks(): Task[] {
    return this.tasks().filter(task => (!this.status || task.status === this.status)
      && task.title.toLocaleLowerCase('ru').includes(this.search.toLocaleLowerCase('ru')));
  }

  async openTask(task: Task): Promise<void> {
    const generation = ++this.detailGeneration;
    this.error.set('');
    this.creating.set(false);
    this.detail.set(null);
    try {
      const detail = await this.api.request<TaskDetail>(`/tasks/${task.id}`);
      if (generation !== this.detailGeneration) return;
      this.detail.set(detail);
      this.comment = '';
      this.draft = {
        title: detail.title, description: detail.description, status: detail.status,
        assignee_id: detail.assignee_id,
        due_at: detail.due_at ? detail.due_at.slice(0, 16) : '',
      };
    } catch (error) {
      if (generation === this.detailGeneration) this.fail(error);
    }
  }

  closeDetail(): void {
    ++this.detailGeneration;
    this.detail.set(null);
    this.creating.set(false);
    this.comment = '';
  }

  createTask(): void {
    this.closeDetail();
    this.draft = this.emptyDraft();
    this.creating.set(true);
  }

  async saveTask(): Promise<void> {
    const project = this.project();
    if (!project || this.saving()) return;
    const userId = this.api.userId;
    const generation = this.detailGeneration;
    const existing = this.detail();
    this.saving.set(true);
    this.error.set('');
    try {
      const data = {
        ...this.draft,
        due_at: this.draft.due_at ? `${this.draft.due_at}:00Z` : null,
        ...(existing ? { version: existing.version } : {}),
      };
      const task = await this.api.request<Task>(existing ? `/tasks/${existing.id}` : `/projects/${project.id}/tasks`, existing ? 'PATCH' : 'POST', data);
      if (userId !== this.api.userId || this.project()?.id !== project.id) return;
      if (!existing) this.projects.update(list => list.map(p => p.id === project.id ? { ...p, task_count: p.task_count + 1 } : p));
      this.tasks.update(list => existing ? list.map(t => t.id === task.id ? task : t) : [...list, task]);
      if (generation === this.detailGeneration) {
        // Saving fields must not erase a comment the user has not sent yet.
        this.detail.set({ ...task, comments: existing?.comments ?? [] });
        this.creating.set(false);
        this.draft = {
          title: task.title, description: task.description, status: task.status,
          assignee_id: task.assignee_id, due_at: task.due_at?.slice(0, 16) ?? '',
        };
        this.notice.set('Задача сохранена');
      }
      void this.refreshAttention();
    } catch (error) { if (generation === this.detailGeneration) this.fail(error); }
    finally { this.saving.set(false); }
  }

  async addComment(): Promise<void> {
    const task = this.detail();
    if (!task || this.saving()) return;
    const generation = this.detailGeneration;
    this.saving.set(true);
    this.error.set('');
    try {
      const comment = await this.api.request<Comment>(`/tasks/${task.id}/comments`, 'POST', { body: this.comment });
      if (generation !== this.detailGeneration) return;
      // Refresh only the discussion: fields may contain unsaved user edits.
      this.detail.update(current => current ? { ...current, comments: [...current.comments, comment] } : current);
      this.comment = '';
      this.notice.set('Комментарий добавлен');
      // The lead's answer may clear "waiting for your reply".
      void this.refreshAttention();
    } catch (error) { if (generation === this.detailGeneration) this.fail(error); }
    finally { this.saving.set(false); }
  }

  statusLabel(status: string): string { return this.statuses.find(s => s.value === status)?.label ?? status; }
  userName(id: number | null): string { return this.context()?.users.find(u => u.id === id)?.name ?? 'Не назначен'; }
  private plural(n: number, one: string, few: string, many: string): string {
    const mod10 = n % 10, mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) return one;
    return mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? few : many;
  }
  private fail(error: unknown): void { this.error.set(error instanceof Error ? error.message : 'Не удалось выполнить запрос'); }
  private emptyDraft(): { title: string; description: string; status: TaskStatus; assignee_id: number | null; due_at: string } {
    return { title: '', description: '', status: 'open', assignee_id: null, due_at: '' };
  }
}
