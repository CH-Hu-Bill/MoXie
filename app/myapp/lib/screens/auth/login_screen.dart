import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../providers/auth_provider.dart';
import '../../theme/app_theme.dart';
import '../../widgets/hand_drawn.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _usernameController = TextEditingController();
  final _passwordController = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  bool _obscurePassword = true;
  bool _loading = false;

  @override
  void dispose() {
    _usernameController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _loading = true);
    final auth = context.read<AuthProvider>();
    await auth.login(
      _usernameController.text.trim(),
      _passwordController.text,
    );
    if (mounted) setState(() => _loading = false);
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    return Scaffold(
      body: PaperTexture(
        child: SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 40),
            child: Form(
              key: _formKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SizedBox(height: 20),
                  const StickyNote(text: '欢迎！'),
                  const SizedBox(height: 16),
                  Text(
                    'ListenWrite',
                    textAlign: TextAlign.center,
                    style: AppTheme.headingStyle.copyWith(fontSize: 42),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '登录或注册（新用户自动注册）',
                    textAlign: TextAlign.center,
                    style: AppTheme.bodyStyle.copyWith(
                      fontSize: 16,
                      color: AppColors.foreground.withValues(alpha: 0.6),
                    ),
                  ),
                  const SizedBox(height: 36),
                  HandDrawnCard(
                    padding: const EdgeInsets.all(24),
                    shadows: AppTheme.hardShadowMd,
                    rotation: 0.5,
                    child: Column(
                      children: [
                        HandDrawnInput(
                          label: '用户名',
                          hint: '输入用户名（2-30字符）',
                          controller: _usernameController,
                          validator: (v) {
                            if (v == null || v.trim().length < 2)
                              return '用户名至少2个字符';
                            if (v.trim().length > 30)
                              return '用户名最多30个字符';
                            return null;
                          },
                        ),
                        const SizedBox(height: 20),
                        HandDrawnInput(
                          label: '密码',
                          hint: '输入密码（至少8位）',
                          controller: _passwordController,
                          obscureText: _obscurePassword,
                          suffix: IconButton(
                            icon: Icon(
                              _obscurePassword
                                  ? Icons.visibility_off
                                  : Icons.visibility,
                            ),
                            onPressed: () => setState(() =>
                                _obscurePassword = !_obscurePassword),
                          ),
                          validator: (v) {
                            if (v == null || v.length < 8)
                              return '密码至少8位';
                            return null;
                          },
                        ),
                        const SizedBox(height: 28),
                        if (auth.error != null)
                          Container(
                            margin: const EdgeInsets.only(bottom: 16),
                            padding: const EdgeInsets.all(12),
                            decoration: BoxDecoration(
                              color: AppColors.accent.withValues(alpha: 0.1),
                              borderRadius: AppTheme.wobblyRadius,
                              border: Border.all(color: AppColors.accent),
                            ),
                            child: Text(
                              auth.error!,
                              style: AppTheme.bodyStyle.copyWith(
                                color: AppColors.accent,
                                fontSize: 14,
                              ),
                            ),
                          ),
                        HandDrawnButton(
                          label: _loading
                              ? '登录中...'
                              : '登录 / 注册',
                          onPressed: _loading
                              ? null
                              : _submit,
                          fullWidth: true,
                          fontSize: 20,
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 24),
                  Text(
                    '提示：新用户输入用户名和密码即可自动注册',
                    textAlign: TextAlign.center,
                    style: AppTheme.bodyStyle.copyWith(
                      fontSize: 14,
                      color: AppColors.foreground.withValues(alpha: 0.4),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
