import {defaultKeymap, history, historyKeymap} from '@codemirror/commands';
import {markdown} from '@codemirror/lang-markdown';
import {Compartment, EditorState} from '@codemirror/state';
import {EditorView, keymap} from '@codemirror/view';

function extensions(readOnlyCompartment, readOnly, onChange) {
  return [
    markdown(),
    history(),
    keymap.of([...historyKeymap, ...defaultKeymap]),
    readOnlyCompartment.of(EditorState.readOnly.of(readOnly)),
    EditorView.contentAttributes.of({
      'aria-label': optionsLabel(onChange),
      'aria-multiline': 'true',
      spellcheck: 'false',
    }),
    EditorView.updateListener.of((update) => {
      if (update.docChanged && onChange) onChange(update.state.doc.toString(), update.changes);
    }),
  ];
}

function optionsLabel(onChange) {
  return onChange ? 'Caxton Markdown source' : 'Caxton source editor proof';
}

export class SourceEditorAdapter {
  constructor(source, options = {}) {
    this.readOnly = options.readOnly === true;
    this.onChange = typeof options.onChange === 'function' ? options.onChange : null;
    this.view = null;
    this.readOnlyCompartment = new Compartment();
    this.state = EditorState.create({
      doc: source,
      extensions: extensions(this.readOnlyCompartment, this.readOnly, this.onChange),
    });
  }

  mount(container) {
    if (!(container instanceof Element)) throw new TypeError('Source editor mount requires an Element.');
    this.destroy();
    this.view = new EditorView({
      state: this.state,
      parent: container,
      dispatch: (transaction) => {
        this.state = transaction.state;
        this.view.update([transaction]);
      },
    });
    this.view.dom.dataset.caxtonSourceProof = 'true';
    return this.view;
  }

  focus() {
    this.view?.focus();
  }

  destroy() {
    this.view?.destroy();
    this.view = null;
  }

  setReadOnly(value) {
    this.readOnly = Boolean(value);
    this.#dispatch({effects: this.readOnlyCompartment.reconfigure(EditorState.readOnly.of(this.readOnly))});
  }

  value() {
    return this.state.doc.toString();
  }

  selection() {
    const main = this.state.selection.main;
    return {from: main.from, to: main.to};
  }

  setSelection(from, to = from) {
    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 0 || to < from || to > this.state.doc.length) {
      return false;
    }
    this.#dispatch({selection: {anchor: from, head: to}});
    return true;
  }

  applyChange(from, to, insert) {
    if (this.readOnly) throw new Error('READ_ONLY');
    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 0 || to < from || to > this.state.doc.length) {
      throw new RangeError('Invalid source change range.');
    }
    if (typeof insert !== 'string' || insert.includes('\u0000')) throw new TypeError('Invalid source insertion.');
    this.#dispatch({changes: {from, to, insert}, selection: {anchor: from + insert.length}});
    return this.value();
  }

  replaceValue(source, selection = null) {
    const nextSelection = selection ?? {anchor: 0};
    this.#dispatch({changes: {from: 0, to: this.state.doc.length, insert: source}, selection: nextSelection});
  }

  #dispatch(spec) {
    if (this.view) {
      this.view.dispatch(spec);
      return;
    }
    this.state = this.state.update(spec).state;
  }
}
