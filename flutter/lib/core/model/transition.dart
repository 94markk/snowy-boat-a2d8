enum TransitionType {
  none('None', ''),
  dissolve('Dissolve', 'fade'),
  fadeBlack('Fade to black', 'fadeblack'),
  fadeWhite('Fade to white', 'fadewhite'),
  slideLeft('Slide left', 'slideleft'),
  slideRight('Slide right', 'slideright'),
  slideUp('Slide up', 'slideup'),
  slideDown('Slide down', 'slidedown'),
  wipeLeft('Wipe left', 'wipeleft'),
  wipeRight('Wipe right', 'wiperight'),
  circleOpen('Circle open', 'circleopen'),
  circleClose('Circle close', 'circleclose'),
  zoomIn('Zoom in', 'zoomin'),
  dissolveBlur('Blur', 'fadegrays');

  const TransitionType(this.label, this.xfadeName);

  final String label;

  /// Name of the matching FFmpeg xfade transition.
  ///
  /// Keeping the mapping on the enum is what stops the preview and the export
  /// drifting: adding a transition means filling in both columns here, and a
  /// missing xfade name is visible at the definition rather than discovered
  /// when a render comes out wrong.
  final String xfadeName;

  bool get isNone => this == TransitionType.none;
}

class Transition {
  const Transition({this.type = TransitionType.none, this.durationUs = 500000});

  final TransitionType type;
  final int durationUs;

  bool get isActive => !type.isNone && durationUs > 0;

  static const Transition none =
      Transition(type: TransitionType.none, durationUs: 0);

  static const int minUs = 100000;
  static const int maxUs = 2000000;

  Transition copyWith({TransitionType? type, int? durationUs}) => Transition(
        type: type ?? this.type,
        durationUs: durationUs ?? this.durationUs,
      );

  Map<String, dynamic> toJson() => {'type': type.name, 'durationUs': durationUs};

  static Transition fromJson(Map<String, dynamic> json) => Transition(
        type: TransitionType.values.firstWhere(
          (t) => t.name == json['type'],
          orElse: () => TransitionType.none,
        ),
        durationUs: (json['durationUs'] as num?)?.toInt() ?? 500000,
      );

  @override
  bool operator ==(Object other) =>
      other is Transition &&
      other.type == type &&
      other.durationUs == durationUs;

  @override
  int get hashCode => Object.hash(type, durationUs);
}
