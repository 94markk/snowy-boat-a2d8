import 'package:material_ui/material_ui.dart';

/// Undo/redo over immutable snapshots.
class History<T> extends ChangeNotifier {
  History(T initial, {this.limit = 60}) : _present = initial;

  final int limit;
  final List<T> _past = [];
  final List<T> _future = [];
  T _present;

  T get value => _present;
  bool get canUndo => _past.isNotEmpty;
  bool get canRedo => _future.isNotEmpty;

  /// Records a new undo step.
  void push(T next) {
    _past.add(_present);
    if (_past.length > limit) _past.removeAt(0);
    _future.clear();
    _present = next;
    notifyListeners();
  }

  /// Updates the present value without creating an undo step (live slider drags).
  void replace(T next) {
    _present = next;
    notifyListeners();
  }

  /// Turns the live changes since [base] into one undo step.
  void commitFrom(T base) {
    _past.add(base);
    if (_past.length > limit) _past.removeAt(0);
    _future.clear();
    notifyListeners();
  }

  void undo() {
    if (!canUndo) return;
    _future.add(_present);
    _present = _past.removeLast();
    notifyListeners();
  }

  void redo() {
    if (!canRedo) return;
    _past.add(_present);
    _present = _future.removeLast();
    notifyListeners();
  }
}
