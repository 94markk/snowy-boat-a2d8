import 'package:flutter/material.dart';

import '../editor/editor_screen.dart';
import '../theme/theme.dart';

/// Landing screen.
///
/// Deliberately thin: the editor is where the work happens, and a launcher
/// that takes two taps to get past is a launcher in the way.
class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Shade.i900,
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SizedBox(height: 24),
              Text(
                'Delicat Studio',
                style: Theme.of(context)
                    .textTheme
                    .titleLarge
                    ?.copyWith(color: Mist.m200),
              ),
              const SizedBox(height: 4),
              Text(
                'Video and photo editing, on device',
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: Mist.m400),
              ),
              const SizedBox(height: 28),
              _NewProjectCard(
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => const EditorScreen(),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _NewProjectCard extends StatelessWidget {
  const _NewProjectCard({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(vertical: 26),
        decoration: BoxDecoration(
          color: Shade.i600,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: Shade.i500),
        ),
        child: Column(
          children: [
            const Icon(Icons.add_rounded, color: aqua, size: 30),
            const SizedBox(height: 8),
            Text(
              'New project',
              style: Theme.of(context)
                  .textTheme
                  .titleMedium
                  ?.copyWith(color: Mist.m200),
            ),
          ],
        ),
      ),
    );
  }
}
